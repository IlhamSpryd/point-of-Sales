<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators;

use App\Support\Dataset\CatalogFixtures;
use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membuat stok awal, purchase order, goods receipt, dan pergerakan stok
 * masuk — semuanya lewat ID hasil lookup (bukan hardcode) dan tanpa
 * menonaktifkan trigger/FK.
 *
 * Saldo stok (ingredient_stock_balances / product_stock_balances) dihitung
 * dari ledger: quantity = Σ movement. Karena itu generator transaksi penjualan
 * WAJIB menulis movement penjualan agar saldo tetap dapat direkonsiliasi.
 */
final class InventoryProcurementGenerator
{
    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * Stok awal (initial_sync) per cabang untuk semua ingredient.
     *
     * @param  array<int,array{id:int,opened_at:CarbonImmutable}>  $stores
     * @param  array<string,int>  $ingredientIds
     */
    public function seedInitialStock(int $tenantId, array $stores, array $ingredientIds): void
    {
        $table = collect(CatalogFixtures::ingredients())->keyBy('code');

        $movements = [];
        $balances = [];

        foreach ($ingredientIds as $code => $id) {
            $def = $table[$code] ?? null;
            if (! $def) {
                continue;
            }
            foreach ($stores as $store) {
                $target = (float) $def['reorder'] * (float) ($this->config->procurement['initial_stock_multiplier'] ?? 3.0);
                $key = $this->config->marker("init:{$tenantId}:{$store['id']}:{$id}");

                $exists = DB::table('ingredient_stock_movements')
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $key)->exists();
                if ($exists) {
                    continue;
                }

                $movements[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'ingredient_id' => $id,
                    'order_id' => null,
                    'order_item_id' => null,
                    'type' => 'initial_sync',
                    'quantity' => round($target, 4),
                    'unit_cost' => $def['cost'],
                    'reason' => 'Stok awal dataset demo',
                    'idempotency_key' => $key,
                    'created_by' => null,
                    'created_at' => $store['opened_at']->subDay(),
                ];

                $balances[$store['id'].':'.$id] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'ingredient_id' => $id,
                    'quantity' => round($target, 6),
                    'baseline_quantity' => round($target, 6),
                    'baseline_movement_id' => 0,
                    'min_stock_level' => (float) $def['reorder'] * 0.4,
                    'reorder_point' => (float) $def['reorder'],
                    'max_stock_level' => (float) $def['reorder'] * 6,
                    'average_unit_cost' => $def['cost'],
                    'last_unit_cost' => $def['cost'],
                    'last_movement_id' => null,
                    'created_at' => $store['opened_at']->subDay(),
                    'updated_at' => $store['opened_at']->subDay(),
                ];
            }
        }

        foreach (array_chunk($movements, $this->writer->batchSize()) as $chunk) {
            DB::table('ingredient_stock_movements')->insert($chunk);
            $this->writer->markWritten('ingredient_stock_movements', count($chunk));
        }

        foreach (array_chunk(array_values($balances), $this->writer->batchSize()) as $chunk) {
            // Balance mungkin sudah ada (Store::created() membuat baris nol).
            DB::table('ingredient_stock_balances')->upsert(
                $chunk,
                ['tenant_id', 'store_id', 'ingredient_id'],
                ['quantity', 'baseline_quantity', 'min_stock_level', 'reorder_point', 'max_stock_level', 'average_unit_cost', 'last_unit_cost', 'updated_at']
            );
            $this->writer->markWritten('ingredient_stock_balances', count($chunk));
        }

        // Retail product stock: initial_sync per cabang.
        $retail = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('track_inventory', 1)
            ->whereNotIn('id', DB::table('product_ingredients')
                ->where('tenant_id', $tenantId)->select('product_id'))
            ->get(['id', 'standard_cost']);

        $productMovements = [];
        $productBalances = [];
        $retailQty = (int) ($this->config->procurement['retail_stock_initial'] ?? 60);

        foreach ($retail as $product) {
            foreach ($stores as $store) {
                $key = $this->config->marker("pinit:{$tenantId}:{$store['id']}:{$product->id}");
                if (DB::table('stock_movements')->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $key)->exists()) {
                    continue;
                }

                $productMovements[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'product_id' => $product->id,
                    'order_id' => null,
                    'order_item_id' => null,
                    'type' => 'initial_sync',
                    'quantity' => $retailQty,
                    'unit_cost' => (float) $product->standard_cost,
                    'reason' => 'Stok awal retail dataset demo',
                    'idempotency_key' => $key,
                    'created_at' => $store['opened_at']->subDay(),
                    'updated_at' => $store['opened_at']->subDay(),
                ];

                $productBalances[$store['id'].':'.$product->id] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'product_id' => $product->id,
                    'quantity' => $retailQty,
                    'baseline_quantity' => $retailQty,
                    'baseline_movement_id' => 0,
                    'min_stock_level' => 10,
                    'reorder_point' => 20,
                    'max_stock_level' => 300,
                    'average_unit_cost' => (float) $product->standard_cost,
                    'last_unit_cost' => (float) $product->standard_cost,
                    'last_movement_id' => null,
                    'created_at' => $store['opened_at']->subDay(),
                    'updated_at' => $store['opened_at']->subDay(),
                ];
            }
        }

        foreach (array_chunk($productMovements, $this->writer->batchSize()) as $chunk) {
            DB::table('stock_movements')->insert($chunk);
            $this->writer->markWritten('stock_movements', count($chunk));
        }
        foreach (array_chunk(array_values($productBalances), $this->writer->batchSize()) as $chunk) {
            DB::table('product_stock_balances')->upsert(
                $chunk,
                ['tenant_id', 'store_id', 'product_id'],
                ['quantity', 'baseline_quantity', 'min_stock_level', 'reorder_point', 'max_stock_level', 'average_unit_cost', 'last_unit_cost', 'updated_at']
            );
            $this->writer->markWritten('product_stock_balances', count($chunk));
        }
    }

    /**
     * Purchase order + goods receipt untuk periode tertentu.
     *
     * Dipanggil per bulan agar transaksi pendek. Setiap PO:
     *  - dibuat untuk ingredient sesuai pemasoknya,
     *  - diterima lewat goods_receipts POSTED + movement goods_receipt,
     *  - saldo stok ditambah sesuai qty diterima.
     *
     * @param  array<int,array{id:int,code:string,opened_at:CarbonImmutable,char:string}>  $stores
     * @param  array<string,int>  $ingredientIds
     * @param  array<string,int>  $supplierIds  code => id
     */
    public function seedProcurement(
        int $tenantId,
        array $stores,
        array $ingredientIds,
        array $supplierIds,
        int $staffUserId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        $rng = (new SeededRandom($this->config->seed))->derive("proc:{$tenantId}:{$from->format('Ym')}");
        $perWeek = max(0, (int) ($this->config->procurement['po_per_store_per_week'] ?? 3));
        if ($perWeek === 0) {
            return 0;
        }

        $ingByGroup = [];
        foreach (array_slice(CatalogFixtures::ingredients(), 0, max(10, $this->config->volumeInt('ingredients', 60))) as $ing) {
            if ((int) $ing['reorder'] <= 0) {
                continue;
            }
            $ingByGroup[$ing['supplier']][] = $ing;
        }

        $poCount = 0;
        $poSeq = 0;
        $products = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->get(['id', 'product_code', 'standard_cost'])->keyBy('id');

        foreach ($stores as $store) {
            $cursor = $from->max($store['opened_at']);

            while ($cursor->lessThanOrEqualTo($to)) {
                // 3x seminggu: Senin, Rabu, Sabtu.
                $dow = (int) $cursor->dayOfWeek; // 0=Minggu
                $isPoDay = in_array($dow, [1, 3, 6], true);
                if (! $isPoDay) {
                    $cursor = $cursor->addDay();

                    continue;
                }

                for ($po = 0; $po < 1; $po++) {
                    $group = $rng->pick(array_keys($ingByGroup));
                    if ($group === null || ! isset($supplierIds[$group])) {
                        continue;
                    }

                    // Nomor urut deterministik per (tenant, cabang) — selalu unik
                    // dan stabil untuk seed/periode yang sama.
                    $poSeq++;
                    $poNumber = sprintf('PO-%s-%s-%04d', $store['code'], $cursor->format('y'), $poSeq);
                    $idemKey = $this->config->marker("po:{$tenantId}:{$store['id']}:{$poNumber}");

                    // Idempoten ganda: idempotency_key DAN po_number (skema
                    // memakai keduanya; po_number unik per tenant di index).
                    if (DB::table('purchase_orders')->where('tenant_id', $tenantId)
                        ->where(fn ($q) => $q->where('idempotency_key', $idemKey)
                            ->orWhere('po_number', $poNumber))
                        ->exists()) {
                        $cursor = $cursor->addDay();

                        continue;
                    }

                    if ($rng->bool((float) ($this->config->procurement['cancel_rate'] ?? 0.03))) {
                        // PO dibatalkan tanpa penerimaan.
                        DB::table('purchase_orders')->insert([
                            'tenant_id' => $tenantId,
                            'store_id' => $store['id'],
                            'supplier_id' => $supplierIds[$group],
                            'po_number' => $poNumber,
                            'revision_number' => 0,
                            'source_type' => 'direct',
                            'order_date' => $cursor->toDateString(),
                            'expected_delivery_date' => null,
                            'payment_terms_days' => 7,
                            'currency_code' => 'IDR',
                            'exchange_rate' => 1,
                            'subtotal_amount' => 0,
                            'discount_amount' => 0,
                            'tax_amount' => 0,
                            'additional_cost_estimate' => 0,
                            'total_amount' => 0,
                            'status' => 'cancelled',
                            'receiving_status' => 'not_received',
                            'invoicing_status' => 'not_invoiced',
                            'created_by_user_id' => $staffUserId,
                            'idempotency_key' => $idemKey,
                            'cancellation_reason' => 'Kebutuhan ditunda',
                            'created_at' => $cursor,
                            'updated_at' => $cursor,
                        ]);
                        $this->writer->markWritten('purchase_orders', 1);
                        $cursor = $cursor->addDay();

                        continue;
                    }

                    $lines = $rng->sample($ingByGroup[$group], $rng->int(3, min(8, count($ingByGroup[$group]))));
                    if ($lines === []) {
                        $cursor = $cursor->addDay();

                        continue;
                    }

                    $leadDays = $this->config->procurement['lead_time_days'] ?? [1, 4];
                    $receivedAt = $cursor->addDays($rng->int($leadDays[0], $leadDays[1]));
                    if ($receivedAt->greaterThan($to)) {
                        $receivedAt = $to;
                    }

                    $subtotal = 0.0;
                    $lineRows = [];
                    $movements = [];

                    foreach ($lines as $ing) {
                        $ingId = $ingredientIds[$ing['code']] ?? null;
                        if (! $ingId) {
                            continue;
                        }

                        $reorder = (float) $ing['reorder'];
                        $qty = round($reorder * $rng->floatBetween(1.2, 2.8), 4);
                        $partial = $rng->bool((float) ($this->config->procurement['partial_receipt_rate'] ?? 0.15));
                        $received = $partial ? round($qty * $rng->floatBetween(0.6, 0.9), 4) : $qty;
                        $unitPrice = (float) $ing['cost'];
                        $lineTotal = round($received * $unitPrice, 2);
                        $subtotal += $lineTotal;

                        $lineRows[] = [
                            'tenant_id' => $tenantId,
                            'purchase_order_id' => 0, // diisi setelah PO dibuat
                            'purchasable_type' => 'ingredient',
                            'purchasable_id' => $ingId,
                            'purchase_uom' => $ing['unit'],
                            'uom_conversion_factor' => 1,
                            'ordered_qty' => $qty,
                            'base_ordered_qty' => $qty,
                            'received_qty' => $received,
                            'accepted_qty' => $received,
                            'rejected_qty' => 0,
                            'returned_qty' => 0,
                            'invoiced_qty' => 0,
                            'unit_price' => $unitPrice,
                            'discount_pct' => 0,
                            'discount_amount' => 0,
                            'tax_rate' => 0,
                            'tax_inclusive' => 0,
                            'tax_amount' => 0,
                            'line_total' => $lineTotal,
                            'over_receipt_tolerance_pct' => 0,
                            'notes' => null,
                            'created_at' => $cursor,
                            'updated_at' => $cursor,
                        ];

                        $movements[] = [
                            'ingredient_id' => $ingId,
                            'quantity' => $received,
                            'unit_cost' => $unitPrice,
                        ];
                    }

                    if ($lineRows === []) {
                        $cursor = $cursor->addDay();

                        continue;
                    }

                    $poId = (int) DB::table('purchase_orders')->insertGetId([
                        'tenant_id' => $tenantId,
                        'store_id' => $store['id'],
                        'supplier_id' => $supplierIds[$group],
                        'po_number' => $poNumber,
                        'revision_number' => 0,
                        'source_type' => 'direct',
                        'order_date' => $cursor->toDateString(),
                        'expected_delivery_date' => $receivedAt->toDateString(),
                        'payment_terms_days' => 7,
                        'currency_code' => 'IDR',
                        'exchange_rate' => 1,
                        'subtotal_amount' => $subtotal,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'additional_cost_estimate' => 0,
                        'total_amount' => $subtotal,
                        'status' => 'closed',
                        'receiving_status' => 'fully_received',
                        'invoicing_status' => 'not_invoiced',
                        'created_by_user_id' => $staffUserId,
                        'approved_by_user_id' => $staffUserId,
                        'approved_at' => $cursor,
                        'released_by_user_id' => $staffUserId,
                        'released_at' => $cursor,
                        'idempotency_key' => $idemKey,
                        'notes' => 'Dataset demo auto-procurement',
                        'created_at' => $cursor,
                        'updated_at' => $receivedAt,
                    ]);
                    $this->writer->markWritten('purchase_orders', 1);

                    foreach ($lineRows as &$line) {
                        $line['purchase_order_id'] = $poId;
                    }
                    unset($line);

                    $grNumber = sprintf('GR-%s-%s-%04d', $store['code'], $receivedAt->format('ymd'), $poSeq);
                    $grnId = (int) DB::table('goods_received_notes')->insertGetId([
                        'tenant_id' => $tenantId,
                        'store_id' => $store['id'],
                        'supplier_id' => $supplierIds[$group],
                        'purchase_order_id' => $poId,
                        'grn_number' => $grNumber,
                        'supplier_delivery_note_no' => 'DN-'.strtoupper(substr(md5($grNumber), 0, 10)),
                        'status' => 'posted',
                        'received_at' => $receivedAt,
                        'received_by_user_id' => $staffUserId,
                        'posted_at' => $receivedAt,
                        'posted_by_user_id' => $staffUserId,
                        'notes' => 'Penerimaan barang dataset demo',
                        'idempotency_key' => $this->config->marker("grn:{$tenantId}:{$store['id']}:{$grNumber}:{$poNumber}"),
                        'created_at' => $receivedAt,
                        'updated_at' => $receivedAt,
                    ]);

                    $grnLines = [];
                    foreach ($lineRows as $i => $pol) {
                        $received = (float) $pol['received_qty'];
                        $grnLines[] = [
                            'tenant_id' => $tenantId,
                            'goods_received_note_id' => $grnId,
                            'purchase_order_line_id' => 0, // diisi setelah PO lines dibuat
                            'line_number' => $i + 1,
                            'purchasable_type' => 'ingredient',
                            'purchasable_id' => $pol['purchasable_id'],
                            'uom' => $pol['purchase_uom'],
                            'uom_conversion_factor' => 1,
                            'received_qty' => $received,
                            'accepted_qty' => $received,
                            'rejected_qty' => 0,
                            'damaged_qty' => 0,
                            'base_accepted_qty' => $received,
                            'unit_cost_snapshot' => (float) $pol['unit_price'],
                            'allocated_landed_cost_per_unit' => 0,
                            'effective_unit_cost' => (float) $pol['unit_price'],
                            'batch_number' => 'B'.$receivedAt->format('ymd').$pol['purchasable_id'],
                            'expiry_date' => $receivedAt->addMonthsNoOverflow($rng->int(3, 12))->toDateString(),
                            'notes' => null,
                            'created_at' => $receivedAt,
                            'updated_at' => $receivedAt,
                        ];
                    }

                    DB::table('purchase_order_lines')->insert($lineRows);
                    $this->writer->markWritten('purchase_order_lines', count($lineRows));

                    // Hubungkan GRN lines ke PO lines yang baru dibuat.
                    $polIds = DB::table('purchase_order_lines')
                        ->where('tenant_id', $tenantId)
                        ->where('purchase_order_id', $poId)
                        ->orderBy('id')
                        ->pluck('id')->all();
                    foreach ($grnLines as $i => &$gl) {
                        $gl['purchase_order_line_id'] = $polIds[$i] ?? 0;
                    }
                    unset($gl);

                    foreach (array_chunk($grnLines, $this->writer->batchSize()) as $chunk) {
                        DB::table('goods_received_note_lines')->insert($chunk);
                        $this->writer->markWritten('goods_received_note_lines', count($chunk));
                    }
                    $this->writer->markWritten('goods_received_notes', 1);

                    foreach ($movements as $mv) {
                        DB::table('ingredient_stock_movements')->insert([
                            'tenant_id' => $tenantId,
                            'store_id' => $store['id'],
                            'ingredient_id' => $mv['ingredient_id'],
                            'order_id' => null,
                            'order_item_id' => null,
                            // chk_ism_type_enum tidak mengenal 'goods_receipt';
                            // tipe yang sah untuk penerimaan barang adalah
                            // 'purchase_receipt'.
                            'type' => 'purchase_receipt',
                            'quantity' => $mv['quantity'],
                            'unit_cost' => $mv['unit_cost'],
                            'reason' => 'Penerimaan '.$grNumber,
                            'idempotency_key' => $this->config->marker("grnmv:{$grnId}:{$mv['ingredient_id']}"),
                            'created_by' => $staffUserId,
                            'created_at' => $receivedAt,
                        ]);
                        $this->writer->markWritten('ingredient_stock_movements', 1);
                    }

                    foreach ($movements as $mv) {
                        DB::table('ingredient_stock_balances')
                            ->where('tenant_id', $tenantId)
                            ->where('store_id', $store['id'])
                            ->where('ingredient_id', $mv['ingredient_id'])
                            ->increment('quantity', $mv['quantity'], ['updated_at' => $receivedAt]);
                    }

                    $poCount++;
                }

                $cursor = $cursor->addDay();
            }
        }

        return $poCount;
    }
}
