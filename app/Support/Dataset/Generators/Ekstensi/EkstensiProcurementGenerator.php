<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators\Ekstensi;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\Generators\Ekstensi\Concerns\ResolvesDatasetContext;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Melengkapi siklus pengadaan end-to-end di atas PO/GRN/faktur yang sudah
 * dibuat generator inti:
 *
 *   purchase_requests, purchase_request_lines,
 *   purchase_order_line_pr_allocations,
 *   purchase_approval_rules, purchase_approval_requests, purchase_approval_logs,
 *   purchase_order_revisions, purchase_additional_costs, purchase_cost_allocations,
 *   purchase_returns, purchase_return_lines,
 *   purchase_debit_notes, purchase_debit_note_applications,
 *   supplier_performance_logs, reorder_suggestions, ingredient_restock_forecasts
 *
 * Tidak mengubah saldo stok/ledger (append-only), sehingga invariant stok
 * dari generator inti tetap hold.
 */
final class EkstensiProcurementGenerator
{
    use ResolvesDatasetContext;

    /** @var array<int, array{id:int,code:string}> */
    private array $stores = [];

    private int $prSeq = 0;

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    public function generate(int $tenantId, int $ownerId): void
    {
        $this->stores = $this->storesWithCode($tenantId);
        if ($this->stores === []) {
            return;
        }

        $this->ensureApprovalRules($tenantId, $ownerId);
        $this->ensurePurchaseRequests($tenantId, $ownerId);
        $this->ensureApprovals($tenantId, $ownerId);
        $this->ensureRevisions($tenantId, $ownerId);
        $this->ensureLandedCosts($tenantId, $ownerId);
        $this->ensurePurchaseReturns($tenantId, $ownerId);
        $this->ensureSupplierReturns($tenantId, $ownerId);
        $this->ensureSupplierPerformance($tenantId, $ownerId);
        $this->ensureReorders($tenantId, $ownerId);
    }

    private function ensureApprovalRules(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('purchase_approval_rules', $tenantId)) {
            return;
        }

        $ts = $this->config->startDate->subDays(60);
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id', 'role_code');
        // [document_type, min, max, role_code, step_order]
        $rules = [
            ['PR', 0, 5_000_000, 'ROL-SUPERVISOR', 1],
            ['PR', 5_000_001, 25_000_000, 'ROL-MANAGER', 1],
            ['PR', 25_000_001, null, 'ROL-OWNER', 1],
            ['PO', 0, 10_000_000, 'ROL-MANAGER', 1],
            ['PO', 10_000_001, null, 'ROL-OWNER', 1],
        ];

        foreach ($rules as [$docType, $min, $max, $roleCode, $step]) {
            DB::table('purchase_approval_rules')->insert([
                'tenant_id' => $tenantId,
                'store_id' => null,
                'document_type' => $docType,
                'currency_code' => 'IDR',
                'min_amount' => $min,
                'max_amount' => $max,
                'step_order' => $step,
                'approver_role_id' => (int) ($roles[$roleCode] ?? 0) ?: null,
                'approver_permission' => null,
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $this->writer->markWritten('purchase_approval_rules', 1);
        }
    }

    /**
     * Purchase request bulanan per cabang, sebagian terkonversi ke PO nyata
     * (lihat purchase_order_line_pr_allocations) dan sebagian masih draft.
     */
    private function ensurePurchaseRequests(int $tenantId, int $ownerId): void
    {
        $rng = $this->rngFor($tenantId, 'purchase_requests');
        $requesterByStore = $this->usersByStore($tenantId, 'ROL-INVENTORY', $ownerId);
        $ingredients = DB::table('ingredients')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'ingredient_code', 'cost_per_unit', 'unit']);

        if ($ingredients->isEmpty()) {
            return;
        }

        $start = $this->config->startDate;
        $end = $this->config->effectiveEndDate();

        foreach ($this->stores as $store) {
            $existing = (int) DB::table('purchase_requests')
                ->where('tenant_id', $tenantId)->where('store_id', $store['id'])->count();
            if ($existing > 0) {
                continue;
            }

            // PO yang sudah dibuat generator inti untuk cabang ini.
            $pos = DB::table('purchase_orders')
                ->where('tenant_id', $tenantId)->where('store_id', $store['id'])
                ->where('status', '<>', 'cancelled')
                ->orderBy('order_date')
                ->get(['id', 'po_number', 'order_date', 'subtotal_amount']);

            foreach ($pos as $po) {
                $this->createPrForPo($tenantId, $store, $po, $rng, $requesterByStore, $ingredients, $ownerId);
            }

            // PR draft / rejected tambahan (tidak terkonversi ke PO).
            $cursor = $this->monthStart($start);
            while ($cursor->lessThanOrEqualTo($end)) {
                if ($rng->bool(0.55)) {
                    $this->createStandalonePr($tenantId, $store, $cursor, $rng, $requesterByStore, $ingredients, $ownerId);
                }
                $cursor = $this->nextMonthStart($cursor);
            }
        }
    }

    private function createPrForPo(
        int $tenantId,
        array $store,
        object $po,
        SeededRandom $rng,
        array $requesterByStore,
        $ingredients,
        int $ownerId,
    ): void {
        $poLines = DB::table('purchase_order_lines')->where('tenant_id', $tenantId)
            ->where('purchase_order_id', $po->id)->orderBy('id')->get();
        if ($poLines->isEmpty()) {
            return;
        }

        $prNumber = 'PR-'.$store['code'].'-'.$this->nextPrSeq();
        $exists = DB::table('purchase_requests')->where('tenant_id', $tenantId)->where('pr_number', $prNumber)->exists();
        if ($exists) {
            return;
        }

        $orderDate = CarbonImmutable::parse($po->order_date);
        $requestedBy = $requesterByStore[$store['id']] ?? $ownerId;
        $submittedAt = $orderDate->subDays(2)->setTime(9, 15);
        $approved = $rng->bool(0.93);

        $prId = (int) DB::table('purchase_requests')->insertGetId([
            'tenant_id' => $tenantId,
            'store_id' => $store['id'],
            'pr_number' => $prNumber,
            'requested_by_user_id' => $requestedBy,
            'required_by_date' => $orderDate->addDays(3)->toDateString(),
            'status' => $approved ? 'closed' : 'rejected',
            'fulfillment_status' => $approved ? 'fully_ordered' : 'unfulfilled',
            'estimated_total_amount' => (float) $po->subtotal_amount,
            'currency_code' => 'IDR',
            'notes' => 'Permintaan pembelian rutin '.$store['code'],
            'rejection_reason' => $approved ? null : 'Anggaran bulan berjalan tidak mencukupi',
            'approved_at' => $approved ? $orderDate->subDay()->setTime(16, 40) : null,
            'approved_by_user_id' => $approved ? $ownerId : null,
            'created_at' => $submittedAt,
            'updated_at' => $approved ? $orderDate->subDay()->setTime(16, 40) : $orderDate->setTime(11, 0),
        ]);
        $this->writer->markWritten('purchase_requests', 1);

        $rows = [];
        $allocations = [];
        foreach ($poLines as $i => $line) {
            $qty = (float) $line->ordered_qty;
            $price = (float) $line->unit_price;
            $rows[] = [
                'tenant_id' => $tenantId,
                'purchase_request_id' => $prId,
                'purchasable_type' => $line->purchasable_type,
                'purchasable_id' => $line->purchasable_id,
                'requested_qty' => $qty,
                'approved_qty' => $approved ? $qty : 0,
                'converted_to_po_qty' => $approved ? $qty : 0,
                'uom' => $line->purchase_uom,
                'estimated_unit_price' => $price,
                'estimated_subtotal' => round($qty * $price, 2),
                'preferred_supplier_id' => null,
                'notes' => null,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ];
        }

        DB::table('purchase_request_lines')->insert($rows);
        $this->writer->markWritten('purchase_request_lines', count($rows));

        if (! $approved) {
            return;
        }

        $prLineIds = DB::table('purchase_request_lines')->where('tenant_id', $tenantId)
            ->where('purchase_request_id', $prId)->orderBy('id')->pluck('id')->all();

        foreach ($poLines->values() as $i => $line) {
            if (empty($prLineIds[$i])) {
                continue;
            }
            $allocations[] = [
                'tenant_id' => $tenantId,
                'purchase_order_line_id' => (int) $line->id,
                'purchase_request_line_id' => (int) ($prLineIds[$i] ?? 0),
                'allocated_qty' => (float) $line->ordered_qty,
                'created_at' => $orderDate,
            ];
        }

        $existingAlloc = DB::table('purchase_order_line_pr_allocations')
            ->where('tenant_id', $tenantId)
            ->whereIn('purchase_order_line_id', array_column($allocations, 'purchase_order_line_id'))
            ->count();
        if ($existingAlloc === 0 && $allocations !== []) {
            DB::table('purchase_order_line_pr_allocations')->insert($allocations);
            $this->writer->markWritten('purchase_order_line_pr_allocations', count($allocations));
        }

        DB::table('purchase_orders')->where('id', $po->id)->update(['source_type' => 'from_pr']);
    }

    private function createStandalonePr(
        int $tenantId,
        array $store,
        CarbonImmutable $month,
        SeededRandom $rng,
        array $requesterByStore,
        $ingredients,
        int $ownerId,
    ): void {
        $prNumber = 'PR-'.$store['code'].'-'.$this->nextPrSeq();
        if (DB::table('purchase_requests')->where('tenant_id', $tenantId)->where('pr_number', $prNumber)->exists()) {
            return;
        }

        $createdAt = $month->addDays($rng->int(1, 12))->setTime($rng->int(8, 16), $rng->int(0, 59));
        $status = $rng->weighted(['draft' => 30, 'submitted' => 35, 'approved' => 15, 'rejected' => 10, 'cancelled' => 10]);
        $picked = $rng->sample($ingredients->all(), $rng->int(2, 6));

        $subtotal = 0.0;
        $rows = [];
        foreach ($picked as $ing) {
            $qty = round((float) $rng->int(2, 20), 2);
            $price = (float) $ing->cost_per_unit;
            $subtotal += $qty * $price;
            $approvedQty = in_array($status, ['approved'], true) ? $qty : 0;
            $rows[] = [
                'tenant_id' => $tenantId,
                'purchase_request_id' => 0,
                'purchasable_type' => 'ingredient',
                'purchasable_id' => (int) $ing->id,
                'requested_qty' => $qty,
                'approved_qty' => $approvedQty,
                'converted_to_po_qty' => 0,
                'uom' => $ing->unit,
                'estimated_unit_price' => $price,
                'estimated_subtotal' => round($qty * $price, 2),
                'preferred_supplier_id' => null,
                'notes' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $prId = (int) DB::table('purchase_requests')->insertGetId([
            'tenant_id' => $tenantId,
            'store_id' => $store['id'],
            'pr_number' => $prNumber,
            'requested_by_user_id' => $requesterByStore[$store['id']] ?? $ownerId,
            'required_by_date' => $createdAt->addDays($rng->int(3, 14))->toDateString(),
            'status' => $status,
            'fulfillment_status' => 'unfulfilled',
            'estimated_total_amount' => round($subtotal, 2),
            'currency_code' => 'IDR',
            'notes' => $rng->pick(['Restock mingguan', 'Kebutuhan event', 'Permintaan barista', 'Antisipasi weekend ramai']),
            'rejection_reason' => $status === 'rejected' ? 'Stok masih mencukupi' : null,
            'approved_at' => $status === 'approved' ? $createdAt->addDay() : null,
            'approved_by_user_id' => $status === 'approved' ? $ownerId : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $this->writer->markWritten('purchase_requests', 1);

        foreach ($rows as &$row) {
            $row['purchase_request_id'] = $prId;
        }
        unset($row);

        DB::table('purchase_request_lines')->insert($rows);
        $this->writer->markWritten('purchase_request_lines', count($rows));
    }

    private function nextPrSeq(): int
    {
        return ++$this->prSeq;
    }

    /**
     * Approval request + log untuk sebagian PO (status approved → released).
     */
    private function ensureApprovals(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('purchase_approval_requests', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'approvals');
        $pos = DB::table('purchase_orders')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['approved', 'released', 'closed'])
            ->orderBy('id')
            ->get(['id', 'store_id', 'po_number', 'total_amount', 'created_by_user_id', 'approved_by_user_id', 'order_date']);

        foreach ($pos as $po) {
            $requestedAt = CarbonImmutable::parse($po->order_date)->setTime(8, 30);
            $decidedAt = $requestedAt->addHours($rng->int(2, 20));
            $approverId = (int) ($po->approved_by_user_id ?? $ownerId);

            $requestId = (int) DB::table('purchase_approval_requests')->insertGetId([
                'tenant_id' => $tenantId,
                'approvable_type' => 'purchase_order',
                'approvable_id' => (int) $po->id,
                'rule_id' => null,
                'step_order' => 1,
                'status' => 'approved',
                'submitted_by_user_id' => (int) $po->created_by_user_id,
                'approved_by_user_id' => $approverId,
                'rejection_reason' => null,
                'decided_at' => $decidedAt,
                'created_at' => $requestedAt,
                'updated_at' => $decidedAt,
            ]);
            $this->writer->markWritten('purchase_approval_requests', 1);

            $this->logApproval($tenantId, $requestId, 'purchase_order', (int) $po->id, 'submitted', (int) $po->created_by_user_id, 'Diajukan untuk persetujuan', $requestedAt);
            $this->logApproval($tenantId, $requestId, 'purchase_order', (int) $po->id, 'approved', $approverId, 'Disetujui sesuai anggaran cabang', $decidedAt);
        }

        // PR yang approved juga punya jejak approval.
        $prs = DB::table('purchase_requests')->where('tenant_id', $tenantId)
            ->where('status', 'approved')->get(['id', 'store_id', 'pr_number', 'requested_by_user_id', 'created_at']);
        foreach ($prs as $pr) {
            $requestedAt = CarbonImmutable::parse($pr->created_at);
            $decidedAt = $requestedAt->addHours($rng->int(3, 26));

            $requestId = (int) DB::table('purchase_approval_requests')->insertGetId([
                'tenant_id' => $tenantId,
                'approvable_type' => 'purchase_request',
                'approvable_id' => (int) $pr->id,
                'rule_id' => null,
                'step_order' => 1,
                'status' => 'approved',
                'submitted_by_user_id' => (int) $pr->requested_by_user_id,
                'approved_by_user_id' => $ownerId,
                'rejection_reason' => null,
                'decided_at' => $decidedAt,
                'created_at' => $requestedAt,
                'updated_at' => $decidedAt,
            ]);
            $this->writer->markWritten('purchase_approval_requests', 1);

            $this->logApproval($tenantId, $requestId, 'purchase_request', (int) $pr->id, 'submitted', (int) $pr->requested_by_user_id, 'Pengajuan kebutuhan cabang', $requestedAt);
            $this->logApproval($tenantId, $requestId, 'purchase_request', (int) $pr->id, 'approved', $ownerId, 'Disetujui pemilik', $decidedAt);
        }
    }

    private function logApproval(
        int $tenantId,
        int $requestId,
        string $type,
        int $documentId,
        string $action,
        int $actorId,
        string $reason,
        CarbonImmutable $at,
    ): void {
        DB::table('purchase_approval_logs')->insert([
            'tenant_id' => $tenantId,
            'approval_request_id' => $requestId,
            'approvable_type' => $type,
            'approvable_id' => $documentId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_role_id' => null,
            'step_order' => 1,
            'reason' => $reason,
            'payload_snapshot' => json_encode(['source' => 'dataset-generation', 'at' => $at->toIso8601String()]),
            'created_at' => $at,
        ]);
        $this->writer->markWritten('purchase_approval_logs', 1);
    }

    /** Revisi PO: sebagian kecil PO diamandemen (harga naik / qty tambahan). */
    private function ensureRevisions(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('purchase_order_revisions', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'revisions');
        $pos = DB::table('purchase_orders')->where('tenant_id', $tenantId)
            ->where('status', '<>', 'cancelled')
            ->where('total_amount', '>', 0)
            ->orderBy('id')->limit(300)->get();

        foreach ($pos as $po) {
            if (! $rng->bool(0.04)) {
                continue;
            }

            $amendedAt = CarbonImmutable::parse($po->order_date)->addDays($rng->int(1, 3))->setTime(14, 5);
            $before = (float) $po->total_amount;
            $after = round($before * $rng->floatBetween(1.02, 1.12), 2);

            DB::table('purchase_order_revisions')->insert([
                'tenant_id' => $tenantId,
                'purchase_order_id' => $po->id,
                'revision_number' => 1,
                'amended_by_user_id' => $ownerId,
                'amended_at' => $amendedAt,
                'reason' => 'Penyesuaian harga pemasok & kuantitas',
                'snapshot_data' => json_encode([
                    'po_number' => $po->po_number,
                    'total_amount' => $before,
                    'status' => $po->status,
                    'lines_count' => DB::table('purchase_order_lines')->where('purchase_order_id', $po->id)->count(),
                ]),
                'total_amount_before' => $before,
                'total_amount_after' => $after,
                'created_at' => $amendedAt,
            ]);
            $this->writer->markWritten('purchase_order_revisions', 1);

            DB::table('purchase_orders')->where('id', $po->id)->update([
                'revision_number' => 1,
                'total_amount' => $after,
                'status' => $po->status === 'closed' ? 'closed' : 'amended',
                'updated_at' => $amendedAt,
            ]);
        }
    }

    /**
     * Landed cost (ongkir/handling) untuk sebagian GRN + alokasi ke baris
     * penerimaan. effective_unit_cost di goods_received_note_lines ikut
     * disesuaikan agar faktur & landed cost konsisten.
     */
    private function ensureLandedCosts(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('purchase_additional_costs', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'landed');
        $grns = DB::table('goods_received_notes')->where('tenant_id', $tenantId)
            ->where('status', 'posted')->orderBy('id')->limit(400)->get();

        $costTypes = ['freight' => 'Ongkos kirim', 'handling' => 'Biaya bongkar', 'insurance' => 'Asuransi pengiriman'];

        foreach ($grns as $grn) {
            if (! $rng->bool(0.45)) {
                continue;
            }

            $lines = DB::table('goods_received_note_lines')->where('tenant_id', $tenantId)
                ->where('goods_received_note_id', $grn->id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                continue;
            }

            $base = (float) $lines->sum(fn ($l) => (float) $l->accepted_qty * (float) $l->unit_cost_snapshot);
            if ($base <= 0) {
                continue;
            }

            $type = $rng->weighted(['freight' => 70, 'handling' => 20, 'insurance' => 10]);
            $amount = round($base * $rng->floatBetween(0.01, 0.05), 2);
            $incurredAt = CarbonImmutable::parse($grn->received_at)->addHours(3);

            $costId = (int) DB::table('purchase_additional_costs')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => (int) $grn->store_id,
                'goods_received_note_id' => (int) $grn->id,
                'purchase_order_id' => $grn->purchase_order_id,
                'cost_code' => 'LC-'.$grn->grn_number.'-'.strtoupper($type),
                'category' => strtoupper($type),
                'description' => ($costTypes[$type] ?? 'Biaya tambahan').' '.$grn->grn_number,
                'amount' => $amount,
                'currency_code' => 'IDR',
                'exchange_rate' => 1,
                'allocation_method' => 'BY_VALUE',
                'vendor_name' => 'Ekspedisi Nusantara',
                'invoice_reference' => 'EXP-'.strtoupper(substr(md5($grn->grn_number.'lc'), 0, 10)),
                'status' => 'allocated',
                'allocated_at' => $incurredAt,
                'created_by' => $ownerId,
                'allocated_by' => $ownerId,
                'idempotency_key' => $this->config->marker("landed:{$grn->id}"),
                'notes' => 'Biaya tambahan penerimaan barang',
                'created_at' => $incurredAt,
                'updated_at' => $incurredAt,
            ]);
            $this->writer->markWritten('purchase_additional_costs', 1);

            $totalQty = (float) $lines->sum(fn ($l) => (float) $l->accepted_qty);
            $allocRows = [];
            $running = 0.0;
            $lastIdx = $lines->count() - 1;
            foreach ($lines->values() as $idx => $line) {
                $share = $totalQty > 0 ? ((float) $line->accepted_qty / $totalQty) : 0;
                // Baris terakhir menyerap sisa pembulatan agar Σ == amount.
                $allocated = $idx === $lastIdx ? round($amount - $running, 2) : round($amount * $share, 2);
                $running += $allocated;
                $allocRows[] = [
                    'tenant_id' => $tenantId,
                    'purchase_additional_cost_id' => $costId,
                    'goods_received_note_line_id' => (int) $line->id,
                    'allocated_amount' => $allocated,
                    'allocated_cost_per_unit' => (float) $line->accepted_qty > 0
                        ? round($allocated / (float) $line->accepted_qty, 4)
                        : 0,
                    'weight_ratio' => round($share, 8),
                    'is_residual_line' => $idx === $lastIdx ? 1 : 0,
                    'is_post_receipt_adjustment' => 0,
                    'created_at' => $incurredAt,
                    'updated_at' => $incurredAt,
                ];
            }
            DB::table('purchase_cost_allocations')->insert($allocRows);
            $this->writer->markWritten('purchase_cost_allocations', count($allocRows));

            foreach ($lines as $line) {
                $qty = (float) $line->accepted_qty;
                if ($qty <= 0) {
                    continue;
                }
                $perUnit = round(($amount * ($qty / max($totalQty, 0.0001))) / $qty, 4);
                DB::table('goods_received_note_lines')->where('id', $line->id)->update([
                    'allocated_landed_cost_per_unit' => $perUnit,
                    'effective_unit_cost' => round((float) $line->unit_cost_snapshot + $perUnit, 4),
                ]);
            }
        }
    }

    /**
     * Retur pembelian (barang rusak/tidak sesuai) + debit note yang
     * diterapkan ke faktur pemasok. Tidak mengubah ledger stok.
     */
    private function ensurePurchaseReturns(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('purchase_returns', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'purchasereturn');
        $grns = DB::table('goods_received_notes')->where('tenant_id', $tenantId)
            ->where('status', 'posted')->orderBy('id')->limit(250)->get();

        $seq = 0;
        foreach ($grns as $grn) {
            if (! $rng->bool(0.12)) {
                continue;
            }

            $lines = DB::table('goods_received_note_lines')->where('tenant_id', $tenantId)
                ->where('goods_received_note_id', $grn->id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                continue;
            }

            $returnedAt = CarbonImmutable::parse($grn->received_at)->addDays($rng->int(1, 4))->setTime(10, 30);
            if ($returnedAt->greaterThan($this->config->effectiveEndDate())) {
                continue;
            }

            $seq++;
            $returnNumber = sprintf('PRET-%s-%04d', $returnedAt->format('ymd'), $seq);
            $pick = $lines->shuffle()->take($rng->int(1, min(2, $lines->count())));

            $rows = [];
            $subtotal = 0.0;
            $lineNo = 0;
            foreach ($pick as $line) {
                $qty = round(min((float) $line->accepted_qty, (float) $line->accepted_qty * $rng->floatBetween(0.1, 0.5)), 4);
                if ($qty <= 0) {
                    continue;
                }
                $unit = (float) $line->effective_unit_cost;
                $lineSubtotal = round($qty * $unit, 2);
                $subtotal += $lineSubtotal;
                $lineNo++;

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'purchase_return_id' => 0,
                    'goods_received_note_line_id' => (int) $line->id,
                    'purchase_order_line_id' => $line->purchase_order_line_id !== null ? (int) $line->purchase_order_line_id : null,
                    'line_number' => $lineNo,
                    'purchasable_type' => $line->purchasable_type,
                    'purchasable_id' => (int) $line->purchasable_id,
                    'return_qty' => $qty,
                    'unit_cost' => $unit,
                    'subtotal' => $lineSubtotal,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'total_amount' => $lineSubtotal,
                    'reason' => $rng->pick(['Kemasan rusak', 'Suhu pengiriman tidak sesuai', 'Kualitas tidak sesuai spesifikasi']),
                    'stock_movement_id' => null,
                    'ingredient_stock_movement_id' => null,
                    'created_at' => $returnedAt,
                    'updated_at' => $returnedAt,
                ];
            }

            if ($rows === []) {
                continue;
            }

            $returnId = (int) DB::table('purchase_returns')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => (int) $grn->store_id,
                'supplier_id' => (int) $grn->supplier_id,
                'goods_received_note_id' => (int) $grn->id,
                'return_number' => $returnNumber,
                'status' => 'posted',
                'reason' => 'Barang tidak sesuai standar kualitas',
                'subtotal_amount' => round($subtotal, 2),
                'tax_amount' => 0,
                'total_amount' => round($subtotal, 2),
                'returned_at' => $returnedAt,
                'posted_at' => $returnedAt->addHours(2),
                'created_by' => $ownerId,
                'posted_by' => $ownerId,
                'idempotency_key' => $this->config->marker("pret:{$grn->id}"),
                'notes' => 'Retur ke pemasok atas penerimaan '.$grn->grn_number,
                'created_at' => $returnedAt,
                'updated_at' => $returnedAt->addHours(2),
            ]);
            $this->writer->markWritten('purchase_returns', 1);

            foreach ($rows as &$row) {
                $row['purchase_return_id'] = $returnId;
            }
            unset($row);
            DB::table('purchase_return_lines')->insert($rows);
            $this->writer->markWritten('purchase_return_lines', count($rows));

            // Debit note diterapkan ke faktur pemasok untuk PO yang sama.
            $invoice = DB::table('supplier_invoices')->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $grn->purchase_order_id)
                ->where('status', 'approved_payable')
                ->orderBy('id')->first();
            if (! $invoice) {
                continue;
            }

            $noteAmount = round($subtotal, 2);
            if ($noteAmount <= 0) {
                continue;
            }

            $dnId = (int) DB::table('purchase_debit_notes')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => (int) $grn->store_id,
                'supplier_id' => (int) $grn->supplier_id,
                'purchase_return_id' => $returnId,
                'debit_note_number' => 'DN-'.$returnNumber,
                'debit_note_date' => $returnedAt->addDay()->toDateString(),
                'currency_code' => 'IDR',
                'subtotal_amount' => $noteAmount,
                'tax_amount' => 0,
                'total_amount' => $noteAmount,
                'applied_amount' => $noteAmount,
                'remaining_amount' => 0,
                'status' => 'applied',
                'supplier_credit_note_number' => 'CN-'.strtoupper(substr(md5($returnNumber), 0, 8)),
                'reason' => 'Potongan atas retur barang '.$returnNumber,
                'created_by' => $ownerId,
                'issued_at' => $returnedAt->addDay(),
                'idempotency_key' => $this->config->marker("dn:{$returnId}"),
                'created_at' => $returnedAt->addDay(),
                'updated_at' => $returnedAt->addDay(),
            ]);
            $this->writer->markWritten('purchase_debit_notes', 1);

            DB::table('purchase_debit_note_applications')->insert([
                'tenant_id' => $tenantId,
                'purchase_debit_note_id' => $dnId,
                'supplier_invoice_id' => (int) $invoice->id,
                'applied_amount' => $noteAmount,
                'applied_at' => $returnedAt->addDay(),
                'applied_by' => $ownerId,
                'notes' => 'Dipotongkan ke faktur '.$invoice->internal_invoice_number,
                'created_at' => $returnedAt->addDay(),
                'updated_at' => $returnedAt->addDay(),
            ]);
            $this->writer->markWritten('purchase_debit_note_applications', 1);
        }
    }

    /**
     * supplier_returns (dokumen legacy) diturunkan dari purchase_returns yang
     * sudah dibuat: satu retur gudang ↔ satu dokumen retur ke pemasok, dengan
     * rujukan ke goods_receipts.
     */
    private function ensureSupplierReturns(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('supplier_returns', $tenantId)) {
            return;
        }

        $rows = DB::table('purchase_returns as pr')
            ->leftJoin('goods_received_notes as grn', 'grn.id', '=', 'pr.goods_received_note_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pr.supplier_id')
            ->leftJoin('goods_receipts as gr', 'gr.receipt_number', '=', DB::raw("CONCAT('GRC-', grn.grn_number)"))
            ->where('pr.tenant_id', $tenantId)
            ->orderBy('pr.id')
            ->get([
                'pr.id', 'pr.store_id', 'pr.return_number', 'pr.reason', 'pr.returned_at', 'pr.posted_at',
                'pr.created_by', 'grn.grn_number', 'grn.supplier_delivery_note_no', 's.name as supplier_name', 'gr.id as goods_receipt_id',
            ]);

        foreach ($rows as $row) {
            $lines = DB::table('purchase_return_lines')->where('tenant_id', $tenantId)
                ->where('purchase_return_id', $row->id)->get();
            if ($lines->isEmpty()) {
                continue;
            }

            $returnedAt = CarbonImmutable::parse($row->returned_at);
            $supplierReturnId = (int) DB::table('supplier_returns')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => (int) $row->store_id,
                'return_number' => 'SR-'.$row->return_number,
                'idempotency_key' => $this->config->marker("sret:{$row->id}"),
                'goods_receipt_id' => $row->goods_receipt_id !== null ? (int) $row->goods_receipt_id : null,
                'supplier_name' => $row->supplier_name,
                'supplier_reference' => $row->supplier_delivery_note_no,
                'status' => 'POSTED',
                'returned_at' => $returnedAt,
                'posted_at' => $returnedAt->addHours(1),
                'reversed_at' => null,
                'created_by' => (int) $row->created_by,
                'posted_by' => (int) $row->created_by,
                'reversed_by' => null,
                'reason' => $row->reason,
                'reversal_reason' => null,
                'notes' => 'Dokumen retur ke pemasok untuk GRN '.$row->grn_number,
                'created_at' => $returnedAt,
                'updated_at' => $returnedAt,
            ]);
            $this->writer->markWritten('supplier_returns', 1);

            $itemRows = [];
            foreach ($lines as $line) {
                $itemRows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int) $row->store_id,
                    'supplier_return_id' => $supplierReturnId,
                    'item_type' => $line->purchasable_type === 'product' ? 'product' : 'ingredient',
                    'product_id' => $line->purchasable_type === 'product' ? (int) $line->purchasable_id : null,
                    'ingredient_id' => $line->purchasable_type === 'product' ? null : (int) $line->purchasable_id,
                    'quantity' => (float) $line->return_qty,
                    'unit_cost' => (float) $line->unit_cost,
                    'reason' => $line->reason,
                    'notes' => null,
                    'created_at' => $returnedAt,
                    'updated_at' => $returnedAt,
                ];
            }

            DB::table('supplier_return_items')->insert($itemRows);
            $this->writer->markWritten('supplier_return_items', count($itemRows));
        }
    }

    /** Skor & insiden pemasok: ketepatan waktu, kualitas, harga. */
    private function ensureSupplierPerformance(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('supplier_performance_logs', $tenantId)) {
            return;
        }

        $grns = DB::table('goods_received_note_lines as gl')
            ->join('goods_received_notes as grn', 'grn.id', '=', 'gl.goods_received_note_id')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'gl.purchase_order_line_id')
            ->join('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->where('grn.tenant_id', $tenantId)
            ->where('grn.status', 'posted')
            ->orderBy('gl.id')
            ->limit(1500)
            ->get([
                'gl.id', 'gl.purchasable_type', 'gl.purchasable_id', 'gl.accepted_qty',
                'gl.effective_unit_cost', 'grn.id as grn_id', 'grn.store_id', 'grn.supplier_id',
                'grn.received_at', 'pol.unit_price', 'pol.accepted_qty as ordered_qty',
                'po.order_date', 'po.expected_delivery_date',
            ]);

        $rows = [];
        foreach ($grns as $line) {
            $receivedAt = CarbonImmutable::parse($line->received_at);
            $expected = $line->expected_delivery_date
                ? CarbonImmutable::parse($line->expected_delivery_date) : $receivedAt;
            $orderDate = CarbonImmutable::parse($line->order_date);

            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $line->store_id,
                'supplier_id' => (int) $line->supplier_id,
                'purchase_order_id' => (int) DB::table('purchase_orders')
                    ->where('id', DB::table('goods_received_notes')->where('id', $line->grn_id)->value('purchase_order_id'))
                    ->value('id'),
                'goods_received_note_id' => (int) $line->grn_id,
                'goods_received_note_line_id' => (int) $line->id,
                'purchasable_type' => $line->purchasable_type,
                'purchasable_id' => (int) $line->purchasable_id,
                'expected_delivery_date' => $expected->toDateString(),
                'received_at' => $receivedAt,
                'order_to_first_receipt_days' => max(0, (int) $orderDate->startOfDay()->diffInDays($receivedAt->startOfDay())),
                'order_to_full_receipt_days' => max(0, (int) $orderDate->startOfDay()->diffInDays($receivedAt->startOfDay())),
                'variance_from_expected_days' => (int) $expected->startOfDay()->diffInDays($receivedAt->startOfDay(), false),
                'ordered_qty' => (float) $line->ordered_qty,
                'accepted_qty' => (float) $line->accepted_qty,
                'unit_price' => (float) $line->unit_price,
                'effective_unit_cost' => (float) $line->effective_unit_cost,
                'recorded_at' => $receivedAt,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ];
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('supplier_performance_logs')->insert($chunk);
            $this->writer->markWritten('supplier_performance_logs', count($chunk));
        }
    }

    /**
     * Reorder suggestion & forecast restock dari konsumsi nyata
     * (ingredient_stock_movements) + saldo stok saat ini.
     */
    private function ensureReorders(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('reorder_suggestions', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'reorder');
        $end = $this->config->effectiveEndDate();

        $consumption = DB::table('ingredient_stock_movements')
            ->selectRaw('store_id, ingredient_id, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as used')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $end->subDays(30)->toDateTimeString())
            ->groupBy('store_id', 'ingredient_id')
            ->get();

        if ($consumption->isEmpty()) {
            return;
        }

        $balances = DB::table('ingredient_stock_balances')->where('tenant_id', $tenantId)
            ->get(['store_id', 'ingredient_id', 'quantity', 'reorder_point', 'average_unit_cost'])
            ->keyBy(fn ($r) => $r->store_id.':'.$r->ingredient_id);

        $reorderRows = [];
        $forecastRows = [];
        $forecastSeen = [];

        foreach ($consumption as $row) {
            $key = $row->store_id.':'.$row->ingredient_id;
            $balance = $balances[$key] ?? null;
            $dailyUse = round((float) $row->used / 30, 4);
            if ($dailyUse <= 0) {
                continue;
            }

            $currentQty = (float) ($balance->quantity ?? 0);
            $reorderPoint = (float) ($balance->reorder_point ?? 0);
            $leadDays = $rng->int(1, 4);
            $suggestedQty = round(max($reorderPoint * 3, $dailyUse * $leadDays * 2) - $currentQty, 2);
            if ($suggestedQty <= 0) {
                continue;
            }

            $neededBy = $end->addDays($leadDays + $rng->int(0, 2));
            $unitCost = (float) ($balance->average_unit_cost ?? 0);

            $reorderRows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $row->store_id,
                'purchasable_type' => 'ingredient',
                'purchasable_id' => (int) $row->ingredient_id,
                'preferred_supplier_id' => null,
                'current_stock' => $currentQty,
                'on_order_po_qty' => 0,
                'open_pr_qty' => 0,
                'net_inventory_position' => $currentQty,
                'daily_consumption_rate' => $dailyUse,
                'lead_time_days' => $leadDays,
                'safety_stock' => round($reorderPoint * 0.5, 4),
                'reorder_point' => $reorderPoint,
                'target_max_stock' => round($reorderPoint * 3, 4),
                'raw_deficit_qty' => $suggestedQty,
                'moq' => 1,
                'order_multiple' => 1,
                'suggested_quantity' => $suggestedQty,
                'purchase_uom' => 'PCS',
                'estimated_unit_price' => $unitCost,
                'estimated_total_cost' => round($suggestedQty * $unitCost, 2),
                'priority_score' => $currentQty <= 0 ? 100 : ($currentQty < $reorderPoint ? 70 : 30),
                'status' => $rng->weighted(['open' => 60, 'converted' => 25, 'dismissed' => 15]),
                'converted_purchase_request_id' => null,
                'dismissed_reason' => null,
                'calculated_at' => $end,
                'created_at' => $end->subDays(1),
                'updated_at' => $end,
            ];

            // Ingredient_restock_forecasts: satu baris per ingredient (unique).
            if (! isset($forecastSeen[$row->ingredient_id])) {
                $forecastSeen[$row->ingredient_id] = true;
                $daysRemaining = $dailyUse > 0 ? round($currentQty / $dailyUse, 2) : null;
                $forecastRows[] = [
                    'tenant_id' => $tenantId,
                    'ingredient_id' => (int) $row->ingredient_id,
                    'avg_daily_consumption' => $dailyUse,
                    'projected_days_remaining' => $daysRemaining,
                    // TIMESTAMP MySQL hanya sampai 2038 dan mulai 1970.
                    'projected_stockout_at' => $daysRemaining !== null
                        ? $end->addDays((int) min(3650, floor($daysRemaining)))
                        : null,
                    'suggested_reorder_qty' => round(max($reorderPoint * 2, $dailyUse * $leadDays * 2), 2),
                    'trend' => $rng->weighted(['up' => 25, 'stable' => 55, 'down' => 20]),
                    'computed_at' => $end,
                    'created_at' => $end,
                    'updated_at' => $end,
                ];
            }
        }

        foreach (array_chunk($reorderRows, $this->writer->batchSize()) as $chunk) {
            DB::table('reorder_suggestions')->insert($chunk);
            $this->writer->markWritten('reorder_suggestions', count($chunk));
        }
        foreach (array_chunk($forecastRows, $this->writer->batchSize()) as $chunk) {
            DB::table('ingredient_restock_forecasts')->insert($chunk);
            $this->writer->markWritten('ingredient_restock_forecasts', count($chunk));
        }
    }
}
