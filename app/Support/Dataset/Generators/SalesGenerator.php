<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generator transaksi penjualan per hari.
 *
 * Konsistensi yang dijaga (mirror dari TransactionService):
 *  - subtotal = Σ(order_price × qty)
 *  - discount dihitung dari diskon yang benar-benar aktif pada tanggal order,
 *  - tax = round((subtotal - discount) × tax_rate)  [tax_rate dari settings]
 *  - order_amount = subtotal - discount + tax, dibulatkan ke rounding_value
 *  - Σ payments.amount (captured) == order_amount  → lolos trigger overpayment
 *  - order_change hanya untuk cash dengan uang diterima > tagihan
 *  - BOM: tiap ingredient yang dikonsumsi → ingredient_stock_movements
 *  - produk non-BOM → stock_movements + saldo product_stock_balances
 *  - saldo ingredient_stock_balances dikurangi sesuai konsumsi
 *
 * ID parent di-resolve dari hasil insert aktual (tidak ada hardcode).
 */
final class SalesGenerator
{
    /** Tarif pajak & pembulatan (dibaca sekali dari settings tenant). */
    private float $taxRate = 0.11;

    private int $roundingValue = 100;

    private string $roundingBehavior = 'ROUND_NEAREST';

    /** @var array<string, array<string,mixed>> product code => product */
    private array $productsByCode = [];

    /** @var array<int, array<string,float>> product_id => [ingredient_code => qty] */
    private array $bomByProduct = [];

    /** @var array<string,int> ingredient code => id */
    private array $ingredientIds = [];

    /** @var array<int, array<int, array<string,mixed>>> tenant_id => modifier map */
    private array $modifierMapByTenant = [];

    /** @var array<int, array<int, int>> store_id => table ids */
    private array $tablesByStore = [];

    private int $orderSeq = 0;

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx  konteks akun hasil TenantFoundationGenerator + CatalogGenerator
     */
    public function prepare(int $tenantId, array $ctx, array $ingredientIds): void
    {
        $this->ingredientIds = $ingredientIds;
        $this->productsByCode = [];
        $this->bomByProduct = [];
        $this->modifierMapByTenant = [];
        $this->tablesByStore = [];

        foreach ($ctx['products'] as $p) {
            $this->productsByCode[$p['code']] = $p;
            if ($p['bom'] !== []) {
                $this->bomByProduct[$p['id']] = $p['bom'];
            }
        }

        $read = fn (string $key, $default) => DB::table('settings')
            ->where('tenant_id', $tenantId)->where('key', $key)->value('value') ?? $default;

        $this->taxRate = (float) $read('tax_rate', config('pos.tax_rate', 0.11));
        $this->roundingValue = (int) $read('rounding_value', config('pos.rounding_value', 100));
        $this->roundingBehavior = (string) $read('rounding_behavior', 'ROUND_NEAREST');

        // Lanjutkan nomor order agar order_code tidak bentrok saat resume.
        $maxSeq = DB::table('orders')->where('tenant_id', $tenantId)
            ->where('order_code', 'like', 'DS-%')
            ->count();
        $this->orderSeq = $maxSeq;
    }

    /** Total order yang direncanakan untuk satu hari/cabang (estimasi). */
    public function plannedOrders(CarbonImmutable $day, string $storeChar): int
    {
        $base = $this->config->volumeInt('orders_per_store_per_day', 60);
        $charMult = (float) ($this->config->volume['store_char_multipliers'][$storeChar] ?? 1.0);
        $weekend = in_array((int) $day->dayOfWeek, [0, 6], true)
            ? (float) ($this->config->volume['weekend_multiplier'] ?? 1.4) : 1.0;
        $season = (float) ($this->config->volume['seasonal_multipliers'][(int) $day->month] ?? 1.0);
        $growth = 0.86 + (0.28 * ($day->diffInDays($this->config->startDate) / max(1, $this->config->totalDays())));
        $holidayBoost = $this->isHoliday($day) ? 1.45 : 1.0;

        return (int) max(5, round($base * $charMult * $weekend * $season * $growth * $holidayBoost));
    }

    /**
     * Generate seluruh transaksi satu hari untuk satu cabang.
     *
     * @param  array<string,mixed>  $storeCtx
     * @param  array<string,mixed>  $ctx
     * @return array<string,int> metrics hari tersebut
     */
    public function generateDay(
        int $tenantId,
        array $storeCtx,
        array $ctx,
        CarbonImmutable $day,
    ): array {
        if ($day->lessThan($storeCtx['opened_at']->startOfDay())) {
            return ['orders' => 0, 'items' => 0, 'payments' => 0];
        }

        $storeId = (int) $storeCtx['id'];
        $seed = $this->config->seed ^ crc32($tenantId.'|'.$storeId.'|'.$day->toDateString());
        $rng = new SeededRandom($seed);

        // Cek checkpoint: hari sudah selesai untuk cabang ini?
        $dayMarker = $this->config->marker("sale:{$tenantId}:{$storeId}:{$day->toDateString()}");
        if (DB::table('orders')->where('tenant_id', $tenantId)->where('idempotency_key', $dayMarker)->exists()) {
            return ['orders' => 0, 'items' => 0, 'payments' => 0];
        }

        $staff = array_values(array_filter(
            $ctx['staff'],
            fn ($s) => (int) $s['store_id'] === $storeId && $s['hired_at']->lessThanOrEqualTo($day)
        ));
        if ($staff === []) {
            return ['orders' => 0, 'items' => 0, 'payments' => 0];
        }

        $cashiers = array_values(array_filter($staff, fn ($s) => $s['role'] === 'ROL-KASIR'));
        $managers = array_values(array_filter($staff, fn ($s) => in_array($s['role'], ['ROL-MANAGER', 'ROL-SUPERVISOR'], true)));
        if ($cashiers === []) {
            $cashiers = $staff;
        }

        $devices = array_values(array_filter($ctx['devices'], fn ($d) => (int) $d['store_id'] === $storeId));
        $deviceIds = array_map(fn ($d) => (int) $d['id'], $devices);
        if ($deviceIds === []) {
            return ['orders' => 0, 'items' => 0, 'payments' => 0];
        }
        $deviceId = $deviceIds[0];

        // Shift per cabang per hari (2 shift: pagi & sore). Satu device hanya
        // boleh punya satu shift aktif (unique `shifts_one_open_per_device_unique`),
        // jadi tiap shift memakai device berbeda — atau tanpa device bila cabang
        // hanya punya satu terminal.
        $shifts = $this->ensureDayShifts($tenantId, $storeId, $deviceIds, $cashiers, $day);
        if ($shifts === []) {
            return ['orders' => 0, 'items' => 0, 'payments' => 0];
        }

        $planned = $this->plannedOrders($day, (string) $storeCtx['char']);
        $customers = $ctx['customers'];
        $tables = $this->tablesByStore[$storeId] ??= DB::table('tables')->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)->whereNull('deleted_at')->pluck('id')->all();
        $modifierMap = $this->modifierMapByTenant[$tenantId] ??= $this->loadModifierMap($tenantId);

        $metrics = ['orders' => 0, 'items' => 0, 'payments' => 0];
        $paymentsByShift = [];
        $cashMovementRows = [];

        for ($i = 0; $i < $planned; $i++) {
            $shift = $this->pickShift($rng, $shifts, $day);
            if ($shift === null) {
                continue;
            }

            /** @var array<int, array<string,mixed>> $orderLevelMovements */
            $orderLevelMovements = [];
            $orderType = $this->pickOrderType($rng);
            $customerId = null;
            if ($rng->bool($this->config->behaviorFloat('customer_attached_rate', 0.38)) && $customers !== []) {
                $candidate = $customers[$rng->int(0, count($customers) - 1)];
                if ($candidate['joined_at']->lessThanOrEqualTo($day)) {
                    $customerId = $candidate['id'];
                }
            }

            // Pilih item berdasarkan popularitas × afinitas waktu.
            $itemCount = (int) $rng->weighted($this->config->volume['items_per_order'] ?? [1 => 34, 2 => 28, 3 => 18, 4 => 10, 5 => 6, 6 => 4]);
            $itemCount = max(1, min($itemCount, 6));
            $hour = $this->pickHour($rng, $orderType);
            $picked = $this->pickProducts($rng, $itemCount, $hour);

            if ($picked === []) {
                continue;
            }

            $lines = [];
            $subtotal = 0;
            foreach ($picked as $product) {
                $qty = (int) $rng->weighted($this->config->volume['qty_per_item'] ?? [1 => 80, 2 => 15, 3 => 4, 4 => 1]);
                $qty = max(1, min($qty, 4));
                $modifiers = [];
                $extra = 0;

                if (! $product['retail']
                    && isset($modifierMap[$product['id']])
                    && $rng->bool($this->config->behaviorFloat('modifier_rate', 0.42))) {
                    foreach ($modifierMap[$product['id']] as $group) {
                        if (! $rng->bool(0.55)) {
                            continue;
                        }
                        $choice = $group['options'][$rng->int(0, count($group['options']) - 1)];
                        $modifiers[] = $choice;
                        $extra += (int) $choice['extra_price'];
                    }
                }

                $unitPrice = (int) $product['price'] + $extra;
                $lineSubtotal = $unitPrice * $qty;
                $subtotal += $lineSubtotal;

                $lines[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_subtotal' => $lineSubtotal,
                    'modifiers' => $modifiers,
                ];
            }

            $discount = $this->pickDiscount($rng, $ctx['discounts'], $day, $subtotal);
            $discountAmount = 0;
            $discountId = null;
            if ($discount !== null) {
                $discountId = (int) $discount->id;
                $discountAmount = $this->computeDiscountAmount($discount, $subtotal);
            }

            [$taxAmount, $totalAmount] = $this->calculateTotals($subtotal, $discountAmount);

            // Pembayaran.
            [$legs, $method, $cashReceived, $change] = $this->buildPayments($rng, $totalAmount);
            $isVoid = $rng->bool($this->config->behaviorFloat('void_rate', 0.006));
            $status = $isVoid ? 'void' : 'paid';

            $at = $this->timestampFor($day, $hour, $rng);
            $orderCode = sprintf('DS-%s-%05d-%s', $storeCtx['code'], ++$this->orderSeq, strtoupper(Str::random(3)));
            $idemKey = $this->config->marker(sprintf('sale:%d:%d:%s:%05d', $tenantId, $storeId, $day->toDateString(), $i));

            // Idempoten per order: hari yang terputus (resume/timeout) tidak
            // menduplikasi order yang sudah terlanjur dibuat.
            if (DB::table('orders')->where('tenant_id', $tenantId)->where('idempotency_key', $idemKey)->exists()) {
                continue;
            }

            // Shift serah terima mungkin punya device berbeda; FK orders
            // mewajibkan device milik shift tersebut.
            $orderDeviceId = (int) ($shift['device_id'] ?? $deviceId);

            $orderId = (int) DB::table('orders')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'user_id' => $shift['cashier_id'],
                'shift_id' => $shift['id'],
                'discount_id' => $discountId,
                'customer_id' => $customerId,
                'table_id' => $orderType === 'dine_in' && $tables !== [] ? $tables[$rng->int(0, count($tables) - 1)] : null,
                'order_type' => $orderType,
                'origin_channel' => 'pos_cashier',
                'source_reference' => null,
                'created_by_device_id' => $orderDeviceId,
                'order_code' => $orderCode,
                'idempotency_key' => $idemKey,
                'order_date' => $day->toDateString(),
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'service_charge_amount' => 0,
                'order_amount' => $totalAmount,
                'order_change' => $change,
                'cash_received' => $cashReceived,
                'order_status' => $status,
                'print_count' => 1,
                'voided_by' => $isVoid && $managers !== [] ? $managers[0]['id'] : null,
                'void_reason' => $isVoid ? 'Salah input kasir' : null,
                'voided_at' => $isVoid ? $at->addMinutes(4) : null,
                'payment_method' => $method,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $metrics['orders']++;

            // ── Order items ──────────────────────────────────────────
            foreach ($lines as $line) {
                $product = $line['product'];
                $itemId = (int) DB::table('order_items')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'order_id' => $orderId,
                    'product_id' => $product['id'],
                    'product_variant_id' => null,
                    'qty' => $line['qty'],
                    'order_price' => $line['unit_price'],
                    'order_subtotal' => $line['line_subtotal'],
                    'options' => json_encode(array_map(fn ($m) => [
                        'modifier_id' => $m['id'], 'name' => $m['name'], 'extra_price' => (int) $m['extra_price'],
                    ], $line['modifiers'])),
                    'notes' => null,
                    'preparation_status' => $isVoid ? 'pending' : 'ready',
                    'processed_by' => null,
                    'item_discount_id' => null,
                    'item_discount_amount' => 0,
                    'allocated_order_discount_amount' => 0,
                    'net_line_total' => $line['line_subtotal'],
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
                $metrics['items']++;

                foreach ($line['modifiers'] as $mod) {
                    DB::table('order_item_modifiers')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'order_item_id' => $itemId,
                        'modifier_id' => $mod['id'],
                        'price_at_time' => $mod['extra_price'],
                        'qty' => $line['qty'],
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                }

                if ($isVoid) {
                    continue; // transaksi void tidak memotong stok
                }

                // BOM consumption (ingredient ledger).
                if (isset($this->bomByProduct[$product['id']])) {
                    foreach ($this->bomByProduct[$product['id']] as $ingCode => $qtyPerUnit) {
                        $ingId = $this->ingredientIds[$ingCode] ?? null;
                        if (! $ingId) {
                            continue;
                        }
                        $qty = round((float) $qtyPerUnit * $line['qty'], 4);

                        $movementKey = $this->config->marker("bom:{$orderId}:{$ingId}");
                        if (isset($orderLevelMovements[$ingId])) {
                            // Satu order bisa memakai ingredient yang sama dari dua produk:
                            // gabungkan ke movement yang sama (append-only ⇒ tidak UPDATE).
                            $orderLevelMovements[$ingId]['quantity'] -= $qty;
                        } else {
                            $orderLevelMovements[$ingId] = [
                                'tenant_id' => $tenantId,
                                'store_id' => $storeId,
                                'ingredient_id' => $ingId,
                                'order_id' => $orderId,
                                'order_item_id' => $itemId,
                                'type' => 'sale_deduction',
                                'quantity' => -$qty,
                                'unit_cost' => null,
                                'reason' => 'Konsumsi resep order '.$orderCode,
                                'idempotency_key' => $movementKey,
                                'created_by' => $shift['cashier_id'],
                                'created_at' => $at,
                            ];
                        }
                    }
                } else {
                    // Produk non-BOM (retail) → stock_movements per item.
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'product_id' => $product['id'],
                        'order_id' => $orderId,
                        'order_item_id' => $itemId,
                        'type' => 'sale_deduction',
                        'quantity' => -$line['qty'],
                        'unit_cost' => (float) $product['cost'],
                        'reason' => 'Checkout '.$orderCode,
                        'idempotency_key' => $this->config->marker("sale_item:{$orderId}:{$itemId}"),
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);

                    // Saldo produk TIDAK di-decrement per transaksi: kolom
                    // `quantity` pada product_stock_balances direkonstruksi
                    // dari ledger (stock_movements) oleh rekonsiliasi final
                    // di DatasetRunner (sum quantity >= 0, CHECK psb_quantity_check).
                }
            }

            // Tulis movement BOM teragregasi untuk order ini.
            foreach ($orderLevelMovements as $movement) {
                DB::table('ingredient_stock_movements')->insert($movement);
                $metrics['movements'] = ($metrics['movements'] ?? 0) + 1;
            }

            // ── Payments (Σ amount == order_amount) ──────────────────
            foreach ($legs as $idx => $leg) {
                DB::table('payments')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'device_id' => $orderDeviceId,
                    'order_id' => $orderId,
                    'shift_id' => $shift['id'],
                    'payment_method' => $leg['method'],
                    'amount' => $leg['amount'],
                    'amount_tendered' => $leg['tendered'],
                    'change_amount' => $leg['change'],
                    'reference_number' => $leg['reference'],
                    'status' => $isVoid ? 'voided' : 'captured',
                    'captured_at' => $isVoid ? null : $at,
                    'processed_by' => $shift['cashier_id'],
                    'idempotency_key' => $this->config->marker("pay:{$orderId}:{$idx}"),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
                $metrics['payments']++;

                if (! $isVoid && $leg['method'] === 'cash') {
                    $paymentsByShift[$shift['id']] = ($paymentsByShift[$shift['id']] ?? 0) + $leg['amount'];
                }
            }

            // Loyalty earn (hanya pelanggan terdaftar & order lunas).
            // balance_after = poin terakhir di ledger + earn; trigger DB
            // `trg_loyalty_ledger_sync_account` akan menyinkronkan akun.
            if (! $isVoid && $customerId !== null) {
                $points = (int) floor($subtotal / 1000);
                if ($points > 0) {
                    $lastBalance = (int) (DB::table('loyalty_ledger')
                        ->where('tenant_id', $tenantId)->where('customer_id', $customerId)
                        ->orderByDesc('id')->value('balance_after') ?? 0);

                    DB::table('loyalty_ledger')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'customer_id' => $customerId,
                        'order_id' => $orderId,
                        'type' => 'earn',
                        'points' => $points,
                        'balance_after' => max(0, $lastBalance) + $points,
                        'reference' => 'Perolehan poin order '.$orderCode,
                        'created_by' => null,
                        'created_at' => $at,
                    ]);
                }
            }
        }

        // ── Cash drawer movements per shift (petty expense) ──────────
        foreach ($shifts as $shift) {
            if ($rng->bool($this->config->behaviorFloat('petty_cash_rate', 0.35))) {
                $cashMovementRows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'shift_id' => $shift['id'],
                    'device_id' => $deviceId,
                    'type' => 'cash_out',
                    'category' => 'petty_expense',
                    'amount' => $rng->int(2, 12) * 10000,
                    'reason' => 'Pembelian operasional harian cabang',
                    'receipt_reference' => 'NOTA-'.$day->format('ymd').'-'.$shift['id'],
                    'created_by' => $shift['cashier_id'],
                    'approved_by' => $managers[0]['id'] ?? $shift['cashier_id'],
                    'idempotency_key' => $this->config->marker("petty:{$shift['id']}"),
                    'created_at' => $shift['closed_at']->subMinutes(45),
                    'updated_at' => $shift['closed_at']->subMinutes(45),
                ];
            }
        }
        if ($cashMovementRows !== []) {
            $existing = DB::table('cash_drawer_movements')
                ->where('tenant_id', $tenantId)
                ->whereIn('idempotency_key', array_column($cashMovementRows, 'idempotency_key'))
                ->pluck('idempotency_key')->flip()->all();
            $cashMovementRows = array_values(array_filter(
                $cashMovementRows,
                fn (array $row) => ! isset($existing[$row['idempotency_key']])
            ));
        }
        if ($cashMovementRows !== []) {
            DB::table('cash_drawer_movements')->insert($cashMovementRows);
            $this->writer->markWritten('cash_drawer_movements', count($cashMovementRows));
        }

        // ── Tutup shift: expected_cash dihitung dari ledger pembayaran ──
        foreach ($shifts as $shift) {
            $cashSales = $paymentsByShift[$shift['id']] ?? 0;
            $cashOut = (int) DB::table('cash_drawer_movements')
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift['id'])
                ->where('type', 'cash_out')->sum('amount');
            $expected = max(0, $shift['opening_balance'] + $cashSales - $cashOut);
            $difference = $rng->weighted([0 => 70, 1 => 10, -1 => 8, 2 => 6, -2 => 6]);
            $closing = max(0, $expected + ($difference * 1000));

            DB::table('shifts')->where('id', $shift['id'])->update([
                'status' => 'closed',
                'closed_at' => $shift['closed_at'],
                'closed_by_user_id' => $shift['cashier_id'],
                'closing_balance' => $closing,
                'expected_cash' => $expected,
                'cash_difference' => $closing - $expected,
                'close_type' => 'normal',
                'updated_at' => $shift['closed_at'],
            ]);
        }

        // Saldo bahan baku (ingredient_stock_balances) direkonstruksi dari ledger
        // (ingredient_stock_movements) oleh rekonsiliasi final di DerivativeGenerator
        // agar konsisten 100% tanpa ribuan query agregasi lambat setiap hari.

        return $metrics;
    }

    /**
     * Buat shift pagi & sore untuk satu cabang; shift ditutup nanti setelah
     * transaksi selesai di generator.
     *
     * @param  array<int,int>  $deviceIds
     * @param  array<int,array{id:int}>  $cashiers
     * @return array<int, array{id:int,cashier_id:int,opening_balance:int,closed_at:CarbonImmutable,start_hour:int,end_hour:int,status:string,device_id:?int}>
     */
    private function ensureDayShifts(int $tenantId, int $storeId, array $deviceIds, array $cashiers, CarbonImmutable $day): array
    {
        $openingHour = (int) ($this->config->period['opening_hour'] ?? 7);
        $closingHour = (int) ($this->config->period['closing_hour'] ?? 22);
        $mid = (int) floor(($openingHour + $closingHour) / 2);

        $plan = [
            ['cashier' => $cashiers[0], 'start' => $openingHour, 'end' => $mid],
            ['cashier' => $cashiers[1] ?? $cashiers[0], 'start' => $mid, 'end' => $closingHour],
        ];

        $result = [];
        foreach ($plan as $idx => $slot) {
            $openedAt = $day->setTime($slot['start'], 0);
            $closedAt = $day->setTime($slot['end'], 0);
            $cashierId = (int) $slot['cashier']['id'];
            $slotDeviceId = $deviceIds[$idx] ?? null;

            // Idempoten per (tenant, store, cashier, slot). `opened_at` sudah
            // memuat tanggal + jam, jadi cukup satu perbandingan exact.
            $existing = DB::table('shifts')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('user_id', $cashierId)
                ->where('opened_at', $openedAt->toDateTimeString())
                ->first();

            if ($existing) {
                $result[] = [
                    'id' => (int) $existing->id,
                    'cashier_id' => $cashierId,
                    'opening_balance' => (int) $existing->opening_balance,
                    'closed_at' => $closedAt,
                    'start_hour' => $slot['start'],
                    'end_hour' => $slot['end'],
                    'status' => $existing->status,
                    'device_id' => $existing->device_id !== null ? (int) $existing->device_id : null,
                ];

                continue;
            }

            $openingBalance = 500000; // modal awal standar kasir

            $shiftId = (int) DB::table('shifts')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'device_id' => $slotDeviceId,
                'user_id' => $cashierId,
                'active_cashier_id' => $cashierId,
                'opening_balance' => $openingBalance,
                'status' => 'open',
                'opened_at' => $openedAt,
                'close_type' => 'normal',
                'notes' => 'Shift dataset demo',
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ]);

            $result[] = [
                'id' => $shiftId,
                'cashier_id' => $cashierId,
                'opening_balance' => $openingBalance,
                'closed_at' => $closedAt,
                'start_hour' => $slot['start'],
                'end_hour' => $slot['end'],
                'status' => 'open',
                'device_id' => $slotDeviceId,
            ];
        }

        return $result;
    }

    /** @param array<int,array{status:string}> $shifts */
    private function pickShift(SeededRandom $rng, array $shifts, CarbonImmutable $day): ?array
    {
        $open = array_values(array_filter($shifts, fn ($s) => $s['status'] === 'open'));
        if ($open === []) {
            return null;
        }

        return $open[count($open) === 1 ? 0 : $rng->int(0, count($open) - 1)];
    }

    private function pickOrderType(SeededRandom $rng): string
    {
        $type = (string) $rng->weighted($this->config->behavior['order_types'] ?? ['dine_in' => 46, 'takeaway' => 44, 'delivery' => 10]);

        return in_array($type, ['dine_in', 'takeaway', 'delivery'], true) ? $type : 'takeaway';
    }

    /** Jam operasional dengan dua puncak (pagi & sore). */
    private function pickHour(SeededRandom $rng, string $orderType): int
    {
        $open = (int) ($this->config->period['opening_hour'] ?? 7);
        $close = (int) ($this->config->period['closing_hour'] ?? 22);

        $weights = [];
        for ($h = $open; $h < $close; $h++) {
            $w = 30;
            if ($h >= 7 && $h <= 10) {
                $w = 90;   // sarapan kopi
            } elseif ($h >= 12 && $h <= 14) {
                $w = 110;  // makan siang
            } elseif ($h >= 15 && $h <= 18) {
                $w = 80;   // sore
            } elseif ($h >= 19) {
                $w = 55;   // malam
            }
            if ($orderType === 'dine_in' && $h >= 11 && $h <= 20) {
                $w += 25;
            }
            if ($orderType === 'delivery' && $h >= 11 && $h <= 19) {
                $w += 20;
            }
            $weights[$h] = $w;
        }

        return (int) $rng->weighted($weights);
    }

    /**
     * Pilih produk dengan bobot popularitas + afinitas jam.
     *
     * @return array<int, array<string,mixed>>
     */
    private function pickProducts(SeededRandom $rng, int $count, int $hour): array
    {
        $all = array_values($this->productsByCode);
        if ($all === []) {
            return [];
        }

        $weights = [];
        foreach ($all as $p) {
            $w = max(1, (int) $p['popularity']);
            if (in_array($p['category'], ['PST'], true) && $hour < 11) {
                $w = (int) ($w * 2.2);
            }
            if (in_array($p['category'], ['RIC', 'PAS', 'SNK'], true) && $hour >= 11 && $hour <= 20) {
                $w = (int) ($w * 1.9);
            }
            if (in_array($p['category'], ['ESP', 'SIG', 'MLK'], true) && ($hour <= 10 || $hour >= 15)) {
                $w = (int) ($w * 1.4);
            }
            if ($p['retail']) {
                $w = max(1, (int) round($w * 0.15));
            }
            $weights[$p['code']] = $w;
        }

        $picked = [];
        $used = [];
        $attempts = 0;

        while (count($picked) < $count && $attempts < $count * 6) {
            $attempts++;
            $code = (string) $rng->weighted($weights);
            if (isset($used[$code])) {
                continue;
            }
            $used[$code] = true;
            $picked[] = $this->productsByCode[$code];
        }

        return $picked;
    }

    /** @return array<int, array> product_id => groups */
    private function loadModifierMap(int $tenantId): array
    {
        $rows = DB::table('modifier_group_product as mgp')
            ->join('modifier_groups as mg', 'mg.id', '=', 'mgp.modifier_group_id')
            ->join('modifiers as m', 'm.modifier_group_id', '=', 'mg.id')
            ->where('mgp.tenant_id', $tenantId)
            ->where('m.is_active', 1)
            ->whereNull('mg.deleted_at')
            ->whereNull('m.deleted_at')
            ->get(['mgp.product_id', 'mg.id as group_id', 'mg.name as group_name', 'm.id', 'm.name', 'm.extra_price'])
            ->groupBy('product_id');

        $map = [];
        foreach ($rows as $productId => $groupRows) {
            $groups = [];
            foreach ($groupRows->groupBy('group_id') as $groupId => $mods) {
                $groups[] = [
                    'id' => $groupId,
                    'name' => $mods->first()->group_name,
                    'options' => $mods->map(fn ($m) => [
                        'id' => (int) $m->id,
                        'name' => $m->name,
                        'extra_price' => (int) $m->extra_price,
                    ])->values()->all(),
                ];
            }
            $map[(int) $productId] = $groups;
        }

        return $map;
    }

    private function pickDiscount(SeededRandom $rng, array $discounts, CarbonImmutable $day, int $subtotal): ?object
    {
        if (! $rng->bool($this->config->behaviorFloat('discount_rate', 0.12)) || $discounts === []) {
            return null;
        }

        $eligible = array_values(array_filter($discounts, function ($d) use ($day, $subtotal) {
            return (int) $d->is_active === 1
                && (int) $d->min_purchase_amount <= $subtotal
                && ($d->valid_from === null || CarbonImmutable::parse($d->valid_from)->lessThanOrEqualTo($day->endOfDay()))
                && ($d->valid_until === null || CarbonImmutable::parse($d->valid_until)->greaterThanOrEqualTo($day->startOfDay()));
        }));

        if ($eligible === []) {
            return null;
        }

        $discount = $eligible[$rng->int(0, count($eligible) - 1)];

        return is_object($discount) ? $discount : (object) $discount;
    }

    private function computeDiscountAmount(object $discount, int $subtotal): int
    {
        $value = (int) $discount->value;
        $amount = $discount->type === 'percentage'
            ? (int) round($subtotal * ($value / 100))
            : $value;

        if (! empty($discount->max_discount_amount)) {
            $amount = min($amount, (int) $discount->max_discount_amount);
        }

        return max(0, min($amount, $subtotal));
    }

    /**
     * Replika TransactionService: pajak dihitung dari (subtotal - diskon),
     * total dibulatkan ke rounding_value, lalu pajak disesuaikan dengan selisih
     * pembulatan agar invariant `total = subtotal - discount + tax` HOLD.
     *
     * @return array{0:int,1:int} [tax, total]
     */
    private function calculateTotals(int $subtotal, int $discount): array
    {
        $discounted = max(0, $subtotal - $discount);
        $tax = (int) round($discounted * $this->taxRate);
        $total = $discounted + $tax;

        if ($this->roundingValue <= 0 || $this->roundingBehavior === 'NONE') {
            return [$tax, $total];
        }

        $total = (int) match ($this->roundingBehavior) {
            'ROUND_NEAREST' => round($total / $this->roundingValue) * $this->roundingValue,
            'ROUND_UP' => ceil($total / $this->roundingValue) * $this->roundingValue,
            'ROUND_DOWN' => floor($total / $this->roundingValue) * $this->roundingValue,
            default => round($total),
        };

        // Pajak menyerap selisih pembulatan sehingga total tetap konsisten.
        $tax = max(0, $total - $discounted);

        return [$tax, $total];
    }

    /**
     * Bangun leg pembayaran: Σ amount == total tagihan (kontrak exact-sum).
     *
     * @return array{0:array<int,array<string,mixed>>,1:string,2:?int,3:int}
     */
    private function buildPayments(SeededRandom $rng, int $total): array
    {
        $methods = $this->config->behavior['payment_methods'] ?? ['qris' => 34, 'cash' => 28, 'card' => 20, 'ewallet' => 18];
        $method = (string) $rng->weighted($methods);
        if (! in_array($method, ['cash', 'qris', 'ewallet', 'card'], true)) {
            $method = 'cash';
        }

        $split = $rng->bool($this->config->behaviorFloat('split_payment_rate', 0.06)) && $total > 50000;
        $legs = [];
        $cashReceived = null;
        $change = 0;

        if ($split) {
            $firstMethod = $method === 'cash' ? 'qris' : 'cash';
            $firstAmount = (int) round($total * $rng->floatBetween(0.35, 0.65) / 100) * 100;
            $firstAmount = max(1000, min($total - 1000, $firstAmount));
            $secondAmount = $total - $firstAmount;

            $legs[] = $this->buildLeg($rng, $firstMethod, $firstAmount);
            $legs[] = $this->buildLeg($rng, $method, $secondAmount);

            if ($firstMethod === 'cash') {
                $tendered = $legs[0]['tendered'];
                $cashReceived = $tendered;
                $change = max(0, $tendered - $firstAmount);
            } elseif ($method === 'cash') {
                $cashReceived = $legs[1]['tendered'];
                $change = max(0, $legs[1]['tendered'] - $secondAmount);
            }
        } else {
            $legs[] = $this->buildLeg($rng, $method, $total);
            if ($method === 'cash') {
                $cashReceived = $legs[0]['tendered'];
                $change = max(0, $legs[0]['tendered'] - $total);
            }
        }

        // Σ amount harus tepat sama dengan total tagihan.
        $sum = array_sum(array_column($legs, 'amount'));
        if ($sum !== $total) {
            $legs[count($legs) - 1]['amount'] += ($total - $sum);
        }

        return [$legs, $method, $cashReceived, $change];
    }

    /** @return array{method:string,amount:int,tendered:int,change:int,reference:?string} */
    private function buildLeg(SeededRandom $rng, string $method, int $amount): array
    {
        $amount = max(1, $amount);

        if ($method === 'cash') {
            // Uang diterima dibulatkan ke atas kelipatan 5000/10000/50000.
            $unit = $rng->pick([5000, 10000, 20000, 50000]);
            $tendered = (int) ceil($amount / $unit) * $unit;

            return [
                'method' => 'cash',
                'amount' => $amount,
                'tendered' => $tendered,
                'change' => $tendered - $amount,
                'reference' => null,
            ];
        }

        $reference = match ($method) {
            'qris' => 'QR'.strtoupper(Str::random(14)),
            'card' => 'EDC'.strtoupper(Str::random(10)),
            'ewallet' => 'EW'.strtoupper(Str::random(12)),
            default => null,
        };

        return [
            'method' => $method,
            'amount' => $amount,
            'tendered' => $amount,
            'change' => 0,
            'reference' => $reference,
        ];
    }

    private function timestampFor(CarbonImmutable $day, int $hour, SeededRandom $rng): CarbonImmutable
    {
        $minute = $rng->int(0, 59);
        $second = $rng->int(0, 59);

        return $day->setTime($hour, $minute, $second);
    }

    private function isHoliday(CarbonImmutable $day): bool
    {
        // Boost volume pada hari libur nasional utama (tanpa mengklaim kalender lengkap).
        $fixed = ['01-01', '05-01', '08-17', '12-25'];
        $md = $day->format('m-d');

        return in_array($md, $fixed, true);
    }
}
