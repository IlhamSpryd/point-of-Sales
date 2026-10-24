<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generator data turunan: retur/refund, sinkronisasi loyalty account,
 * dan penutupan EOD settlement.
 *
 * Semua referensi berasal dari order yang BENAR-BENAR ada dan lunas.
 * Tidak ada ledger yang di-UPDATE (append-only dihormati).
 */
final class DerivativeGenerator
{
    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * Retur untuk sebagian kecil order lunas dalam rentang tanggal.
     *
     * @return array{returns:int,refund_amount:int}
     */
    public function generateReturns(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rate = min(0.05, max(0.0, $this->config->behaviorFloat('return_rate', 0.012)));
        if ($rate <= 0) {
            return ['returns' => 0, 'refund_amount' => 0];
        }

        $rng = (new SeededRandom($this->config->seed))->derive("returns:{$tenantId}:{$from->format('Ym')}");
        $window = $this->config->behavior['return_window_days'] ?? [0, 21];

        $candidates = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('order_status', 'paid')
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->where('order_amount', '>', 0)
            ->limit(5000)
            ->get(['id', 'store_id', 'user_id', 'order_date', 'subtotal_amount', 'discount_amount', 'tax_amount', 'order_amount']);

        if ($candidates->isEmpty()) {
            return ['returns' => 0, 'refund_amount' => 0];
        }

        $target = (int) max(1, round($candidates->count() * $rate));
        $picked = $candidates->shuffle()->take($target);

        $returns = 0;
        $refunded = 0;

        foreach ($picked as $order) {
            $idem = $this->config->marker("ret:{$order->id}");
            if (DB::table('order_returns')->where('tenant_id', $tenantId)->where('idempotency_key', $idem)->exists()) {
                continue;
            }

            $items = DB::table('order_items')
                ->where('order_id', $order->id)
                ->get(['id', 'product_id', 'qty', 'order_price', 'order_subtotal']);
            if ($items->isEmpty()) {
                continue;
            }

            $returnCount = $items->count() > 1 && $rng->bool(0.25) ? 2 : 1;
            $returned = $items->take($returnCount);

            $subtotalReturned = 0;
            foreach ($returned as $item) {
                $subtotalReturned += (int) $item->order_subtotal;
            }
            $subtotalReturned = min($subtotalReturned, (int) $order->subtotal_amount);
            if ($subtotalReturned <= 0) {
                continue;
            }

            $discountReversed = (int) round(((int) $order->discount_amount) * ($subtotalReturned / max(1, (int) $order->subtotal_amount)));
            $taxReturned = (int) round(((int) $order->tax_amount) * ($subtotalReturned / max(1, (int) $order->subtotal_amount)));
            $totalRefundable = max(1, $subtotalReturned - $discountReversed + $taxReturned);

            // Guard over-refund (DB-013): trigger menolak Σ refunds per payment
            // yang melebihi amount payment. Dengan split payment, pilih leg
            // terbesar dan batasi refund ke sisa refundable leg tersebut.
            // Kolom harus eksplisit: shift_id/device_id dipakai di bawah untuk
            // memenuhi FK order_returns & payment_refunds ke shifts/devices.
            $payment = DB::table('payments')
                ->where('tenant_id', $tenantId)->where('order_id', $order->id)
                ->where('status', 'captured')
                ->orderByDesc('amount')->orderBy('id')
                ->first(['id', 'amount', 'payment_method', 'shift_id', 'device_id']);
            if (! $payment) {
                continue;
            }
            $alreadyRefunded = (int) (DB::table('payment_refunds')
                ->where('payment_id', $payment->id)
                ->where('financial_status', 'settled')
                ->sum('amount'));
            $maxRefundForLeg = (int) $payment->amount - $alreadyRefunded;
            if ($maxRefundForLeg <= 0) {
                continue;
            }
            $totalRefundable = min($totalRefundable, $maxRefundForLeg);

            // order_date hanya berisi tanggal (00:00) → gunakan jam toko agar
            // retur tidak tercatat tepat tengah malam.
            $returnAt = CarbonImmutable::parse($order->order_date)
                ->addDays($rng->int($window[0], $window[1]))
                ->setTime($rng->int(10, 19), $rng->int(0, 59));
            if ($returnAt->greaterThan($this->config->effectiveEndDate())) {
                continue;
            }

            // return_number unik: microseconds + order id (anti-collision
            // pada unique index uq_order_returns_number).
            $returnNumber = sprintf('RTN-%s-%05d', $returnAt->format('ymdHis'), $order->id % 100000);

            $returnId = (int) DB::table('order_returns')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'shift_id' => $payment->shift_id,
                'device_id' => $payment->device_id,
                'return_number' => $returnNumber,
                'idempotency_key' => $idem,
                'status' => 'completed',
                'return_type' => $returned->count() === $items->count() ? 'full' : 'partial',
                'subtotal_returned' => $subtotalReturned,
                'discount_reversed' => $discountReversed,
                'tax_returned' => $taxReturned,
                'service_charge_returned' => 0,
                'total_refundable_amount' => $totalRefundable,
                'reason' => $rng->pick(['Pesanan salah', 'Kualitas produk', 'Pesanan tidak sesuai', 'Produk rusak']),
                'requested_by_user_id' => $order->user_id,
                'approved_by_user_id' => $order->user_id,
                'completed_at' => $returnAt,
                'created_at' => $returnAt,
                'updated_at' => $returnAt,
            ]);

            foreach ($returned as $item) {
                DB::table('order_return_items')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $order->store_id,
                    'order_return_id' => $returnId,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity_returned' => $item->qty,
                    'unit_price_snapshot' => $item->order_price,
                    'line_discount_reversed' => 0,
                    'line_writeoff_or_refund_amount' => (int) $item->order_subtotal,
                    'disposition' => 'restock',
                    'created_at' => $returnAt,
                    'updated_at' => $returnAt,
                ]);
            }

            DB::table('payment_refunds')->insert([
                'tenant_id' => $tenantId,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'order_return_id' => $returnId,
                'shift_id' => $payment->shift_id,
                'device_id' => $payment->device_id,
                'idempotency_key' => $this->config->marker("refund:{$order->id}"),
                'refund_number' => 'RFD-'.$returnNumber,
                'amount' => $totalRefundable,
                'currency' => 'IDR',
                'original_payment_method' => $payment->payment_method,
                'refund_method' => $payment->payment_method === 'cash' ? 'cash' : 'original_method',
                'is_method_override' => 0,
                'request_status' => 'approved',
                'provider_status' => $payment->payment_method === 'cash' ? 'not_applicable' : 'succeeded',
                'financial_status' => 'settled',
                'reason' => 'Retur dataset demo',
                'requested_by_user_id' => $order->user_id,
                'approved_by_user_id' => $order->user_id,
                'settled_at' => $returnAt,
                'created_at' => $returnAt,
                'updated_at' => $returnAt,
            ]);

            $returns++;
            $refunded += $totalRefundable;
        }

        return ['returns' => $returns, 'refund_amount' => $refunded];
    }

    /**
     * Memastikan customer_loyalty_accounts konsisten dengan loyalty_ledger.
     *
     * Trigger `trg_loyalty_ledger_sync_account` sudah men-sync setiap insert,
     * sehingga fungsi ini hanya mengisi akun pelanggan yang belum punya baris
     * (mis. pelanggan lama). Tidak ada UPDATE pada loyalty_ledger.
     */
    public function syncLoyaltyAccounts(int $tenantId): int
    {
        $rows = DB::table('loyalty_ledger')
            ->selectRaw('customer_id, SUM(points) as total_points, MAX(created_at) as last_at')
            ->where('tenant_id', $tenantId)
            ->groupBy('customer_id')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $tiers = DB::table('loyalty_tiers')->where('tenant_id', $tenantId)
            ->orderBy('min_points')->get(['id', 'min_points', 'name']);

        $payload = [];
        foreach ($rows as $row) {
            $points = max(0, (int) $row->total_points);
            $tierId = null;
            foreach ($tiers as $tier) {
                if ($points >= (int) $tier->min_points) {
                    $tierId = (int) $tier->id;
                }
            }

            $payload[] = [
                'tenant_id' => $tenantId,
                'customer_id' => (int) $row->customer_id,
                'current_points' => $points,
                'lifetime_points_earned' => $points,
                'current_tier_id' => $tierId,
                'created_at' => $row->last_at ?? now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, $this->writer->batchSize()) as $chunk) {
            // insertOrIgnore: baris yang sudah dibuat trigger tidak ditimpa.
            DB::table('customer_loyalty_accounts')->insertOrIgnore($chunk);
            $this->writer->markWritten('customer_loyalty_accounts', count($chunk));
        }

        return count($payload);
    }

    /**
     * Rekonsiliasi akhir saldo stok dari ledger.
     *
     * Ledger bersifat append-only, jadi selisih tidak boleh di-update:
     * bila Σ ledger negatif (penjualan melebihi stok + penerimaan), dibuat
     * movement `stock_adjustment` positif (domain sah untuk stock opname),
     * lalu saldo diset tepat = Σ ledger sehingga invariant
     * `saldo == Σ movement` DAN `saldo >= 0` keduanya terpenuhi.
     */
    public function reconcileStockBalances(int $tenantId): array
    {
        $reconciled = 0;
        $adjusted = 0;

        // ── Ingredient ───────────────────────────────────────────────
        $sums = DB::table('ingredient_stock_movements')
            ->selectRaw('store_id, ingredient_id, SUM(quantity) q')
            ->where('tenant_id', $tenantId)
            ->groupBy('store_id', 'ingredient_id')
            ->get();

        $costs = DB::table('ingredients')->where('tenant_id', $tenantId)->pluck('cost_per_unit', 'id');
        $now = now();
        $adjustRows = [];
        $balanceRows = [];

        foreach ($sums as $row) {
            $q = (float) $row->q;
            if ($q < 0) {
                $reconSeq = 1;
                $key = $this->config->marker("recon:{$tenantId}:{$row->store_id}:{$row->ingredient_id}");
                while (DB::table('ingredient_stock_movements')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->exists()) {
                    $reconSeq++;
                    $key = $this->config->marker("recon:{$tenantId}:{$row->store_id}:{$row->ingredient_id}:{$reconSeq}");
                }
                $adjustRows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $row->store_id,
                    'ingredient_id' => $row->ingredient_id,
                    'order_id' => null,
                    'order_item_id' => null,
                    'type' => 'adjustment',
                    'quantity' => round(-$q, 4),
                    'unit_cost' => (float) ($costs[$row->ingredient_id] ?? 0),
                    'reason' => 'Penyesuaian opname akhir dataset (stok minus)',
                    'idempotency_key' => $key,
                    'created_by' => null,
                    'created_at' => $now,
                ];
                $adjusted++;
                $q = 0;
            }
            $balanceRows[$row->store_id.':'.$row->ingredient_id] = [
                'store_id' => $row->store_id, 'ingredient_id' => $row->ingredient_id, 'q' => $q,
            ];
        }

        if ($adjustRows !== []) {
            foreach (array_chunk($adjustRows, $this->writer->batchSize()) as $chunk) {
                DB::table('ingredient_stock_movements')->insertOrIgnore($chunk);
            }
        }

        foreach ($balanceRows as $b) {
            // Baris saldo yang belum ada dibuat terlebih dahulu (Store::created()
            // hanya menyiapkan saldo untuk produk yang sudah ada saat store dibuat).
            $exists = DB::table('ingredient_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $b['store_id'])
                ->where('ingredient_id', $b['ingredient_id'])
                ->exists();
            if (! $exists) {
                DB::table('ingredient_stock_balances')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $b['store_id'],
                    'ingredient_id' => $b['ingredient_id'],
                    'quantity' => 0,
                    'baseline_quantity' => 0,
                    'baseline_movement_id' => 0,
                    'average_unit_cost' => 0,
                    'last_unit_cost' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $reconciled += (int) DB::table('ingredient_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $b['store_id'])
                ->where('ingredient_id', $b['ingredient_id'])
                ->update(['quantity' => round($b['q'], 6), 'updated_at' => $now]);
        }

        // ── Produk retail ────────────────────────────────────────────
        $pSums = DB::table('stock_movements')
            ->selectRaw('store_id, product_id, SUM(quantity) q')
            ->where('tenant_id', $tenantId)
            ->groupBy('store_id', 'product_id')
            ->get();

        $pAdjust = [];
        foreach ($pSums as $row) {
            $q = (float) $row->q;
            if ($q < 0) {
                $reconpSeq = 1;
                $pKey = $this->config->marker("reconp:{$tenantId}:{$row->store_id}:{$row->product_id}");
                while (DB::table('stock_movements')->where('tenant_id', $tenantId)->where('idempotency_key', $pKey)->exists()) {
                    $reconpSeq++;
                    $pKey = $this->config->marker("reconp:{$tenantId}:{$row->store_id}:{$row->product_id}:{$reconpSeq}");
                }
                $pAdjust[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $row->store_id,
                    'product_id' => $row->product_id,
                    'order_id' => null,
                    'order_item_id' => null,
                    'type' => 'adjustment',
                    'quantity' => round(-$q, 4),
                    'unit_cost' => 0,
                    'reason' => 'Penyesuaian opname akhir dataset (stok minus)',
                    'idempotency_key' => $pKey,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $q = 0;
            }
            $pExists = DB::table('product_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $row->store_id)
                ->where('product_id', $row->product_id)
                ->exists();
            if (! $pExists) {
                DB::table('product_stock_balances')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $row->store_id,
                    'product_id' => $row->product_id,
                    'quantity' => 0,
                    'baseline_quantity' => 0,
                    'baseline_movement_id' => 0,
                    'average_unit_cost' => 0,
                    'last_unit_cost' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('product_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $row->store_id)
                ->where('product_id', $row->product_id)
                ->update(['quantity' => round($q, 6), 'updated_at' => $now]);
        }
        if ($pAdjust !== []) {
            foreach (array_chunk($pAdjust, $this->writer->batchSize()) as $chunk) {
                DB::table('stock_movements')->insertOrIgnore($chunk);
            }
        }

        return ['balances_reconciled' => $reconciled, 'adjustments' => $adjusted + count($pAdjust)];
    }

    /**
     * EOD settlement per cabang per hari (hanya hari yang punya order).
     *
     * @param  array<int,array{id:int}>  $stores
     */
    public function generateEodSettlements(int $tenantId, array $stores, CarbonImmutable $from, CarbonImmutable $to, int $closedByUserId): int
    {
        $created = 0;

        foreach ($stores as $store) {
            $daily = DB::table('orders')
                ->selectRaw("order_date, COUNT(*) as cnt, SUM(subtotal_amount) as gross,
                             SUM(discount_amount) as disc, SUM(tax_amount) as tax, SUM(order_amount) as net,
                             SUM(CASE WHEN order_status = 'void' THEN order_amount ELSE 0 END) as voids")
                ->where('tenant_id', $tenantId)
                ->where('store_id', $store['id'])
                ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('order_status', ['paid', 'void'])
                ->groupBy('order_date')
                ->get();

            foreach ($daily as $day) {
                $exists = DB::table('eod_settlements')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $store['id'])
                    ->where('business_date', $day->order_date)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $payments = DB::table('payments')
                    ->where('tenant_id', $tenantId)->where('store_id', $store['id'])
                    ->where('status', 'captured')
                    ->whereDate('created_at', $day->order_date)
                    ->get(['payment_method', 'amount']);

                $breakdown = $payments->groupBy('payment_method')
                    ->map(fn ($g) => (int) $g->sum('amount'))->all();

                $shiftIds = DB::table('shifts')->where('tenant_id', $tenantId)
                    ->where('store_id', $store['id'])
                    ->whereDate('opened_at', $day->order_date)
                    ->pluck('id')->all();

                $refunds = (int) DB::table('payment_refunds')->where('tenant_id', $tenantId)
                    ->where('store_id', $store['id'])
                    ->whereDate('created_at', $day->order_date)
                    ->where('financial_status', 'settled')->sum('amount');

                $expectedCash = (int) DB::table('shifts')->whereIn('id', $shiftIds ?: [0])->where('status', 'closed')->sum('expected_cash');
                $countedCash = (int) DB::table('shifts')->whereIn('id', $shiftIds ?: [0])->where('status', 'closed')->sum('closing_balance');

                DB::table('eod_settlements')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'business_date' => $day->order_date,
                    'status' => 'closed',
                    'total_shifts_count' => count($shiftIds),
                    'gross_sales' => $day->gross,
                    'total_discounts' => $day->disc,
                    'total_returns_refunds' => $refunds,
                    'total_voids' => $day->voids,
                    'net_sales' => $day->net - $day->voids,
                    'total_tax' => $day->tax,
                    'total_service_charge' => 0,
                    'expected_cash_total' => $expectedCash,
                    'counted_cash_total' => $countedCash,
                    'cash_discrepancy_total' => $countedCash - $expectedCash,
                    'payment_breakdown_snapshot' => json_encode($breakdown),
                    'closed_by_user_id' => $closedByUserId,
                    'closed_at' => CarbonImmutable::parse($day->order_date)->endOfDay()->subMinutes(15),
                    'notes' => 'EOD dataset demo',
                    'created_at' => CarbonImmutable::parse($day->order_date)->endOfDay(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        }

        return $created;
    }
}
