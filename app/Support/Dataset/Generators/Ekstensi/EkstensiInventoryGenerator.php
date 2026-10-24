<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators\Ekstensi;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\Generators\Ekstensi\Concerns\ResolvesDatasetContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen inventori operasional yang tidak dibuat generator inti:
 *
 *   stock_adjustments + stock_adjustment_items (damaged / expired / shrinkage)
 *   stock_opnames + stock_opname_items (stock opname bulanan)
 *   stock_transfers + stock_transfer_items (mutasi antar cabang)
 *   goods_receipts + goods_receipt_items (dokumen penerimaan legacy)
 *   product_cost_histories (jejak perubahan HPP)
 *
 * Catatan penting: generator inti merekonsiliasi
 * `ingredient_stock_balances.quantity == Σ ingredient_stock_movements`, jadi
 * dokumen di sini TIDAK menulis movement tambahan. Dokumen tetap nyata dan
 * saling merujuk, sementara ledger tetap menjadi satu-satunya sumber saldo.
 */
final class EkstensiInventoryGenerator
{
    use ResolvesDatasetContext;

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    public function generate(int $tenantId, int $ownerId): void
    {
        $stores = $this->storesWithCode($tenantId);
        if ($stores === []) {
            return;
        }

        $this->ensureAdjustments($tenantId, $stores, $ownerId);
        $this->ensureOpnames($tenantId, $stores, $ownerId);
        $this->ensureTransfers($tenantId, $stores, $ownerId);
        $this->ensureGoodsReceipts($tenantId, $stores, $ownerId);
        $this->ensureProductCostHistories($tenantId, $stores, $ownerId);
    }

    /** @return Collection<int, \stdClass> ingredients per tenant */
    private function ingredients(int $tenantId): Collection
    {
        return DB::table('ingredients')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'ingredient_code', 'cost_per_unit']);
    }

    /** @param array<int, array{id:int,code:string}> $stores */
    private function ensureAdjustments(int $tenantId, array $stores, int $ownerId): void
    {
        if ($this->tenantHasRows('stock_adjustments', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'adjustments');
        $ingredients = $this->ingredients($tenantId);
        $usersByStore = $this->usersByStore($tenantId, 'ROL-INVENTORY', $ownerId);
        if ($ingredients->isEmpty()) {
            return;
        }

        $reasons = ['DAMAGED' => 30, 'EXPIRED' => 25, 'SHRINKAGE' => 25, 'PRODUCTION_WASTE' => 15, 'FOUND' => 5];
        $seq = 0;

        foreach ($stores as $store) {
            // 2 dokumen per bulan per cabang.
            $cursor = $this->monthStart($this->config->startDate);
            $end = $this->config->effectiveEndDate();

            while ($cursor->lessThanOrEqualTo($end)) {
                for ($n = 0; $n < 2; $n++) {
                    $seq++;
                    $at = $cursor->addDays($rng->int(2, 24))->setTime($rng->int(9, 18), $rng->int(0, 59));
                    if ($at->greaterThan($end)) {
                        continue;
                    }

                    $reason = $rng->weighted($reasons);
                    $status = $rng->weighted(['POSTED' => 85, 'DRAFT' => 10, 'CANCELLED' => 5]);
                    $number = sprintf('ADJ-%s-%s-%04d', $store['code'], $at->format('ym'), $seq);

                    $adjustmentId = (int) DB::table('stock_adjustments')->insertGetId([
                        'tenant_id' => $tenantId,
                        'store_id' => $store['id'],
                        'adjustment_number' => $number,
                        'idempotency_key' => $this->config->marker("adj:{$tenantId}:{$store['id']}:{$number}"),
                        'status' => $status,
                        'reason_code' => $reason,
                        'adjusted_at' => $at,
                        'posted_at' => $status === 'POSTED' ? $at->addHours(2) : null,
                        'created_by' => $usersByStore[$store['id']] ?? $ownerId,
                        'posted_by' => $status === 'POSTED' ? $ownerId : null,
                        'notes' => 'Penyesuaian stok '.strtolower($reason),
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                    $this->writer->markWritten('stock_adjustments', 1);

                    $rows = [];
                    foreach ($rng->sample($ingredients->all(), $rng->int(2, 5)) as $ing) {
                        $qty = round($rng->floatBetween(0.5, 8.0), 4);
                        $rows[] = [
                            'tenant_id' => $tenantId,
                            'store_id' => $store['id'],
                            'stock_adjustment_id' => $adjustmentId,
                            'item_type' => 'ingredient',
                            'product_id' => null,
                            'ingredient_id' => (int) $ing->id,
                            'direction' => $reason === 'FOUND' ? 'IN' : 'OUT',
                            'quantity' => $qty,
                            'unit_cost_snapshot' => (float) $ing->cost_per_unit,
                            'reason_note' => $reason === 'EXPIRED' ? 'Melewati tanggal kedaluwarsa' : 'Temuan pemeriksaan stok',
                            'created_at' => $at,
                            'updated_at' => $at,
                        ];
                    }

                    DB::table('stock_adjustment_items')->insert($rows);
                    $this->writer->markWritten('stock_adjustment_items', count($rows));
                }

                $cursor = $this->nextMonthStart($cursor);
            }
        }
    }

    /** @param array<int, array{id:int,code:string}> $stores */
    private function ensureOpnames(int $tenantId, array $stores, int $ownerId): void
    {
        if ($this->tenantHasRows('stock_opnames', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'opname');
        $ingredients = $this->ingredients($tenantId);
        $usersByStore = $this->usersByStore($tenantId, 'ROL-INVENTORY', $ownerId);
        if ($ingredients->isEmpty()) {
            return;
        }

        $balances = DB::table('ingredient_stock_balances')->where('tenant_id', $tenantId)
            ->get(['store_id', 'ingredient_id', 'quantity', 'last_unit_cost'])
            ->keyBy(fn ($r) => $r->store_id.':'.$r->ingredient_id);

        $seq = 0;
        foreach ($stores as $store) {
            $cursor = $this->monthStart($this->config->startDate);
            $end = $this->config->effectiveEndDate();

            while ($cursor->lessThanOrEqualTo($end)) {
                $seq++;
                // Opname dilakukan pada minggu terakhir bulan berjalan; dibatasi
                // agar tetap di dalam periode dataset (mode --limit-days).
                $monthEnd = $cursor->endOfMonth()->startOfDay();
                $candidate = $monthEnd->subDays($rng->int(0, 3));
                $countedAt = $candidate->min($end->startOfDay())->setTime(21, 30);
                if ($countedAt->greaterThan($end)) {
                    $cursor = $this->nextMonthStart($cursor);

                    continue;
                }

                $status = $countedAt->greaterThan($end->subDays(5))
                    ? $rng->weighted(['PENDING_APPROVAL' => 50, 'COUNTING' => 30, 'APPROVED' => 20])
                    : 'APPROVED';
                $number = sprintf('OPN-%s-%s', $store['code'], $countedAt->format('Ym'));

                $opnameId = (int) DB::table('stock_opnames')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'opname_number' => $number,
                    'idempotency_key' => $this->config->marker("opn:{$tenantId}:{$store['id']}:{$number}"),
                    'status' => $status,
                    'snapshot_taken_at' => $countedAt->subHours(2),
                    'submitted_at' => $status === 'COUNTING' ? null : $countedAt->addHours(1),
                    'approved_at' => $status === 'APPROVED' ? $countedAt->addHours(6) : null,
                    'created_by' => $usersByStore[$store['id']] ?? $ownerId,
                    'submitted_by' => $status === 'COUNTING' ? null : $usersByStore[$store['id']] ?? $ownerId,
                    'approved_by' => $status === 'APPROVED' ? $ownerId : null,
                    'notes' => 'Stock opname bulanan '.$store['code'],
                    'created_at' => $countedAt->subHours(2),
                    'updated_at' => $countedAt,
                ]);
                $this->writer->markWritten('stock_opnames', 1);

                $rows = [];
                foreach ($rng->sample($ingredients->all(), $rng->int(6, 12)) as $ing) {
                    $snapshot = (float) ($balances[$store['id'].':'.$ing->id]->quantity ?? 0);
                    $counted = $status === 'COUNTING'
                        ? null
                        : max(0, round($snapshot * $rng->floatBetween(0.9, 1.05), 4));
                    $variance = $counted !== null ? round($counted - $snapshot, 4) : null;

                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'store_id' => $store['id'],
                        'stock_opname_id' => $opnameId,
                        'item_type' => 'ingredient',
                        'product_id' => null,
                        'ingredient_id' => (int) $ing->id,
                        'snapshot_on_hand' => $snapshot,
                        'snapshot_last_movement_id' => null,
                        'counted_quantity' => $counted,
                        'variance_quantity' => $variance,
                        'drift_resolution_mode' => $variance !== null && abs($variance) > 0.001 ? 'REBASE_OVERRIDE' : 'NONE',
                        'reason_code' => $variance !== null && abs($variance) > 0.001 ? 'SHRINKAGE' : null,
                        'notes' => null,
                        'created_at' => $countedAt,
                        'updated_at' => $countedAt,
                    ];
                }

                DB::table('stock_opname_items')->insert($rows);
                $this->writer->markWritten('stock_opname_items', count($rows));

                $cursor = $this->nextMonthStart($cursor);
            }
        }
    }

    /** @param array<int, array{id:int,code:string}> $stores */
    private function ensureTransfers(int $tenantId, array $stores, int $ownerId): void
    {
        if ($this->tenantHasRows('stock_transfers', $tenantId)) {
            return;
        }
        if (count($stores) < 2) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'transfers');
        $ingredients = $this->ingredients($tenantId);
        $usersByStore = $this->usersByStore($tenantId, 'ROL-INVENTORY', $ownerId);
        if ($ingredients->isEmpty()) {
            return;
        }

        $seq = 0;
        $cursor = $this->monthStart($this->config->startDate);
        $end = $this->config->effectiveEndDate();

        while ($cursor->lessThanOrEqualTo($end)) {
            for ($n = 0; $n < 3; $n++) {
                $seq++;
                $fromIdx = $rng->int(0, count($stores) - 1);
                $toIdx = ($fromIdx + 1 + $rng->int(0, count($stores) - 2)) % count($stores);
                $from = $stores[$fromIdx];
                $to = $stores[$toIdx];

                $shippedAt = $cursor->addDays($rng->int(1, 25))->setTime($rng->int(9, 16), 0);
                if ($shippedAt->greaterThan($end)) {
                    continue;
                }

                $status = $rng->weighted(['RECEIVED' => 78, 'IN_TRANSIT' => 12, 'CANCELLED' => 5, 'DRAFT' => 5]);
                $receivedAt = $status === 'RECEIVED' ? $shippedAt->addHours($rng->int(4, 30)) : null;
                $number = sprintf('TRF-%s-%s-%03d', $from['code'], $shippedAt->format('ym'), $seq);

                $transferId = (int) DB::table('stock_transfers')->insertGetId([
                    'tenant_id' => $tenantId,
                    'from_store_id' => $from['id'],
                    'to_store_id' => $to['id'],
                    'transfer_number' => $number,
                    'ship_idempotency_key' => $this->config->marker("trf:{$tenantId}:{$number}"),
                    'receive_idempotency_key' => $status === 'RECEIVED'
                        ? $this->config->marker("trfrecv:{$tenantId}:{$number}") : null,
                    'status' => $status,
                    'shipped_at' => in_array($status, ['RECEIVED', 'IN_TRANSIT'], true) ? $shippedAt : null,
                    'received_at' => $receivedAt,
                    'cancelled_at' => $status === 'CANCELLED' ? $shippedAt->addHour() : null,
                    'created_by' => $usersByStore[$from['id']] ?? $ownerId,
                    'shipped_by' => in_array($status, ['RECEIVED', 'IN_TRANSIT'], true) ? $usersByStore[$from['id']] ?? $ownerId : null,
                    'received_by' => $receivedAt ? $usersByStore[$to['id']] ?? $ownerId : null,
                    'cancelled_by' => $status === 'CANCELLED' ? $ownerId : null,
                    'notes' => 'Mutasi stok '.$from['code'].' → '.$to['code'],
                    'created_at' => $shippedAt,
                    'updated_at' => $receivedAt ?? $shippedAt,
                ]);
                $this->writer->markWritten('stock_transfers', 1);

                $rows = [];
                foreach ($rng->sample($ingredients->all(), $rng->int(2, 5)) as $ing) {
                    $requested = round($rng->floatBetween(2, 15), 4);
                    $shipped = $status === 'DRAFT' ? 0.0 : round($requested * $rng->floatBetween(0.85, 1.0), 4);
                    $received = $status === 'RECEIVED'
                        ? round($shipped * $rng->floatBetween(0.95, 1.0), 4)
                        : null;
                    $discrepancy = $received !== null ? round($shipped - $received, 4) : 0.0;

                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'stock_transfer_id' => $transferId,
                        'item_type' => 'ingredient',
                        'product_id' => null,
                        'ingredient_id' => (int) $ing->id,
                        'quantity_requested' => $requested,
                        'quantity_shipped' => $shipped,
                        'quantity_received' => $received,
                        'discrepancy_quantity' => max(0, $discrepancy),
                        'discrepancy_resolution' => $discrepancy > 0.001 ? 'WRITE_OFF_IN_TRANSIT' : 'NONE',
                        'discrepancy_reason_code' => $discrepancy > 0.001 ? 'TRANSIT_LOSS' : null,
                        'carrying_unit_cost' => (float) $ing->cost_per_unit,
                        'notes' => null,
                        'created_at' => $shippedAt,
                        'updated_at' => $receivedAt ?? $shippedAt,
                    ];
                }

                DB::table('stock_transfer_items')->insert($rows);
                $this->writer->markWritten('stock_transfer_items', count($rows));
            }

            $cursor = $this->nextMonthStart($cursor);
        }
    }

    /**
     * goods_receipts: dokumen legacy per penerimaan. Dibuat dari GRN aktual
     * (satu penerimaan = satu dokumen) agar referensi PO/pemasok konsisten.
     *
     * @param  array<int, array{id:int,code:string}>  $stores
     */
    private function ensureGoodsReceipts(int $tenantId, array $stores, int $ownerId): void
    {
        if ($this->tenantHasRows('goods_receipts', $tenantId)) {
            return;
        }

        $receipts = DB::table('goods_received_notes as grn')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->where('grn.tenant_id', $tenantId)
            ->where('grn.status', 'posted')
            ->orderBy('grn.id')
            ->limit(1200)
            ->get(['grn.id', 'grn.store_id', 'grn.grn_number', 'grn.received_at', 'grn.received_by_user_id',
                'grn.supplier_delivery_note_no', 'po.po_number']);

        foreach ($receipts as $grn) {
            $receivedAt = CarbonImmutable::parse($grn->received_at);
            $receiptNumber = 'GRC-'.$grn->grn_number;

            $receiptId = (int) DB::table('goods_receipts')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => (int) $grn->store_id,
                'receipt_number' => $receiptNumber,
                'idempotency_key' => $this->config->marker("grc:{$grn->id}"),
                'supplier_reference' => $grn->supplier_delivery_note_no,
                'purchase_order_reference' => $grn->po_number,
                'status' => 'POSTED',
                'received_at' => $receivedAt,
                'posted_at' => $receivedAt->addMinutes(35),
                'reversed_at' => null,
                'created_by' => (int) ($grn->received_by_user_id ?? $ownerId),
                'posted_by' => (int) ($grn->received_by_user_id ?? $ownerId),
                'reversed_by' => null,
                'reversal_reason' => null,
                'notes' => 'Penerimaan barang fisik (dokumen gudang)',
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
            $this->writer->markWritten('goods_receipts', 1);

            $lines = DB::table('goods_received_note_lines')->where('tenant_id', $tenantId)
                ->where('goods_received_note_id', $grn->id)->get();

            $rows = [];
            foreach ($lines as $line) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int) $grn->store_id,
                    'goods_receipt_id' => $receiptId,
                    'item_type' => $line->purchasable_type === 'product' ? 'product' : 'ingredient',
                    'product_id' => $line->purchasable_type === 'product' ? (int) $line->purchasable_id : null,
                    'ingredient_id' => $line->purchasable_type === 'product' ? null : (int) $line->purchasable_id,
                    'quantity_received' => (float) $line->accepted_qty,
                    'unit_cost' => (float) $line->effective_unit_cost,
                    'batch_number' => $line->batch_number,
                    'expiry_date' => $line->expiry_date,
                    'notes' => null,
                    'created_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ];
            }

            if ($rows !== []) {
                DB::table('goods_receipt_items')->insert($rows);
                $this->writer->markWritten('goods_receipt_items', count($rows));
            }
        }
    }

    /**
     * product_cost_histories: jejak HPP dari perubahan harga beli bahan.
     *
     * @param  array<int, array{id:int,code:string}>  $stores
     */
    private function ensureProductCostHistories(int $tenantId, array $stores, int $ownerId): void
    {
        if ($this->tenantHasRows('product_cost_histories', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'costhistory');
        // Generator inti menyimpan SKU tanpa kolom standard_cost terisi
        // (kolom legacy), jadi HPP diambil dari cost_per_unit bahan via BOM.
        $products = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->limit(200)->get(['id', 'product_price', 'standard_cost']);
        $storeId = $stores[0]['id'];
        $changedBy = $this->userWithRole($tenantId, 'ROL-MANAGER', $ownerId);

        $rows = [];
        foreach ($products as $product) {
            $old = (float) $product->standard_cost;
            if ($old <= 0) {
                // Fallback deterministik: 32% harga jual (margin khas cafe).
                $old = round((float) $product->product_price * 0.32, 2);
            }
            if ($old <= 0) {
                continue;
            }

            foreach ([0, 1] as $round) {
                // Sebar di dua titik periode (1/3 dan 2/3 durasi dataset) agar
                // tetap terisi pada mode smoke (--limit-days) maupun 12 bulan.
                $offsetDays = (int) round($this->config->totalDays() * (0.3 + (0.35 * $round)));
                $at = $this->config->startDate->addDays(max(1, $offsetDays))->setTime(9, 0);
                if ($at->greaterThan($this->config->effectiveEndDate())) {
                    continue;
                }
                $new = round($old * $rng->floatBetween(1.02, 1.09), 2);

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'product_id' => (int) $product->id,
                    'costing_method' => 'standard',
                    'old_cost' => $old,
                    'new_cost' => $new,
                    'reason' => $round === 0 ? 'Kenaikan harga bahan baku' : 'Pembaruan harga pemasok',
                    'changed_by' => $changedBy,
                    'effective_at' => $at,
                    'created_at' => $at,
                ];
                $old = $new;
            }
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('product_cost_histories')->insert($chunk);
            $this->writer->markWritten('product_cost_histories', count($chunk));
        }
    }
}
