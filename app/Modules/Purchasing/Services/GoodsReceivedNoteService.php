<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\DamageDispositionEnum;
use App\Enums\GrnStatusEnum;
use App\Enums\PoReceivingStatusEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\StockMovementType;
use App\Models\User;
use App\Modules\Finance\Services\InventoryAccountingService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Purchasing\Exceptions\OverReceiptToleranceExceededException;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Services\AuditTrailService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceivedNoteService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected InventoryLedgerService $inventoryLedgerService,
        protected AuditTrailService $auditTrailService,
        protected ?SupplierPerformanceService $performanceService = null
    ) {
        $this->performanceService = $performanceService ?? app(SupplierPerformanceService::class);
    }

    /**
     * Membuat draf Goods Received Note (GRN) dari PO yang valid.
     *
     * @param  array{
     *     received_at?: string,
     *     supplier_delivery_note_no?: ?string,
     *     notes?: ?string
     * }  $headerData
     * @param  array<int, array{
     *     purchase_order_line_id: int,
     *     received_qty: string|float|int,
     *     accepted_qty?: string|float|int,
     *     rejected_qty?: string|float|int,
     *     damaged_qty?: string|float|int,
     *     damage_disposition?: ?string,
     *     rejection_reason?: ?string,
     *     uom_conversion_factor?: string|float|int,
     *     batch_number?: ?string,
     *     expiry_date?: ?string,
     *     storage_location?: ?string,
     *     notes?: ?string
     * }>  $linesData
     *
     * @throws ValidationException|OverReceiptToleranceExceededException
     */
    public function createGoodsReceivedNote(
        PurchaseOrder $po,
        array $headerData,
        array $linesData,
        User $actor
    ): GoodsReceivedNote {
        $tenantId = (int) $po->tenant_id;
        $storeId = (int) $po->store_id;

        // Validasi status PO yang eligible untuk penerimaan
        if (! in_array($po->status, [PurchaseOrderStatusEnum::Approved, PurchaseOrderStatusEnum::Released, PurchaseOrderStatusEnum::Amended], true)) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ["PO #{$po->po_number} belum disetujui atau diterbitkan (Status: {$po->status->value})."],
            ]);
        }

        if ($po->receiving_status === PoReceivingStatusEnum::FullyReceived) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ["PO #{$po->po_number} sudah berstatus Diterima Penuh (Fully Received)."],
            ]);
        }

        if (empty($linesData)) {
            throw ValidationException::withMessages([
                'lines' => ['Minimal harus ada 1 baris penerimaan barang pada GRN.'],
            ]);
        }

        return DB::transaction(function () use ($po, $headerData, $linesData, $actor, $tenantId, $storeId) {
            $grnNumber = $this->sequenceService->nextNumber(
                tenantId: $tenantId,
                docType: DocumentSequenceService::TYPE_GOODS_RECEIVED_NOTE,
                storeId: $storeId
            );

            /** @var GoodsReceivedNote $grn */
            $grn = GoodsReceivedNote::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'supplier_id' => (int) $po->supplier_id,
                'purchase_order_id' => (int) $po->id,
                'grn_number' => $grnNumber,
                'supplier_delivery_note_no' => $headerData['supplier_delivery_note_no'] ?? null,
                'status' => GrnStatusEnum::Draft,
                'received_at' => $headerData['received_at'] ?? now(),
                'received_by_user_id' => $actor->id,
                'notes' => $headerData['notes'] ?? null,
            ]);

            $poLines = $po->lines()->get()->keyBy('id');
            $lineNumber = 1;

            foreach ($linesData as $index => $lineItem) {
                $polId = (int) $lineItem['purchase_order_line_id'];
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLines->get($polId);

                if (! $poLine) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.purchase_order_line_id" => ["Baris PO #{$polId} tidak valid untuk PO #{$po->po_number}."],
                    ]);
                }

                $receivedQty = (string) $lineItem['received_qty'];
                $acceptedQty = (string) ($lineItem['accepted_qty'] ?? $receivedQty);
                $rejectedQty = (string) ($lineItem['rejected_qty'] ?? '0.0000');
                $damagedQty = (string) ($lineItem['damaged_qty'] ?? '0.0000');

                // Invariant chk_grnl_qtys & chk_grnl_qty_balance
                if (bccomp($receivedQty, '0', 4) < 0 || bccomp($acceptedQty, '0', 4) < 0 || bccomp($rejectedQty, '0', 4) < 0 || bccomp($damagedQty, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => ['Kuantitas pada baris penerimaan tidak boleh bernilai negatif.'],
                    ]);
                }

                $sumAccRej = bcadd($acceptedQty, $rejectedQty, 4);
                if (bccomp($receivedQty, $sumAccRej, 4) !== 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.received_qty" => [
                            "Kuantitas diterima ({$receivedQty}) harus tepat sama dengan jumlah diterima baik ({$acceptedQty}) ditambah ditolak ({$rejectedQty}).",
                        ],
                    ]);
                }

                // Validasi toleransi over-receipt
                $tolerancePct = $poLine->over_receipt_tolerance_pct ?? '0.00';
                $toleranceRate = bcdiv((string) $tolerancePct, '100', 4);
                $maxAllowed = bcmul((string) $poLine->ordered_qty, bcadd('1', $toleranceRate, 4), 4);
                $projectedAccepted = bcadd((string) $poLine->accepted_qty, $acceptedQty, 4);

                if (bccomp($projectedAccepted, $maxAllowed, 4) > 0) {
                    throw new OverReceiptToleranceExceededException(
                        "Kuantitas diterima ({$projectedAccepted}) melebihi batas maksimum penerimaan ({$maxAllowed}, toleransi {$tolerancePct}%) untuk baris PO #{$poLine->id}."
                    );
                }

                $uomFactor = (string) ($lineItem['uom_conversion_factor'] ?? $poLine->uom_conversion_factor ?? '1.000000');
                if (bccomp($uomFactor, '0', 6) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.uom_conversion_factor" => ['Faktor konversi UoM harus lebih besar dari 0.'],
                    ]);
                }

                $baseAcceptedQty = bcmul($acceptedQty, $uomFactor, 4);
                $unitCostSnapshot = bccomp($uomFactor, '1.000000', 6) === 0
                    ? (string) $poLine->unit_price
                    : bcdiv((string) $poLine->unit_price, $uomFactor, 4);

                $damageDisposition = ! empty($lineItem['damage_disposition'])
                    ? DamageDispositionEnum::tryFrom((string) $lineItem['damage_disposition'])
                    : null;

                GoodsReceivedNoteLine::query()->create([
                    'tenant_id' => $tenantId,
                    'goods_received_note_id' => $grn->id,
                    'purchase_order_line_id' => $poLine->id,
                    'line_number' => $lineNumber++,
                    'purchasable_type' => $poLine->purchasable_type,
                    'purchasable_id' => (int) $poLine->purchasable_id,
                    'uom' => $poLine->purchase_uom,
                    'uom_conversion_factor' => $uomFactor,
                    'received_qty' => $receivedQty,
                    'accepted_qty' => $acceptedQty,
                    'rejected_qty' => $rejectedQty,
                    'damaged_qty' => $damagedQty,
                    'damage_disposition' => $damageDisposition,
                    'rejection_reason' => $lineItem['rejection_reason'] ?? null,
                    'base_accepted_qty' => $baseAcceptedQty,
                    'unit_cost_snapshot' => $unitCostSnapshot,
                    'allocated_landed_cost_per_unit' => '0.0000',
                    'effective_unit_cost' => $unitCostSnapshot,
                    'batch_number' => $lineItem['batch_number'] ?? null,
                    'expiry_date' => $lineItem['expiry_date'] ?? null,
                    'storage_location' => $lineItem['storage_location'] ?? null,
                    'notes' => $lineItem['notes'] ?? null,
                ]);
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'grn.created',
                userId: $actor->id,
                subjectType: GoodsReceivedNote::class,
                subjectId: $grn->id,
                description: "Draf GRN #{$grn->grn_number} dibuat atas PO #{$po->po_number}.",
                metadata: [
                    'purchase_order_id' => $po->id,
                    'po_number' => $po->po_number,
                    'lines_count' => count($linesData),
                ]
            );

            return $grn->fresh(['lines']);
        });
    }

    /**
     * Memperbarui draf Goods Received Note (GRN) sebelum diposting.
     *
     * @param  array{
     *     received_at?: string,
     *     supplier_delivery_note_no?: ?string,
     *     notes?: ?string
     * }  $headerData
     * @param  array<int, array{
     *     purchase_order_line_id: int,
     *     received_qty: string|float|int,
     *     accepted_qty?: string|float|int,
     *     rejected_qty?: string|float|int,
     *     damaged_qty?: string|float|int,
     *     damage_disposition?: ?string,
     *     rejection_reason?: ?string,
     *     uom_conversion_factor?: string|float|int,
     *     batch_number?: ?string,
     *     expiry_date?: ?string,
     *     storage_location?: ?string,
     *     notes?: ?string
     * }>  $linesData
     */
    public function updateDraftGrn(
        GoodsReceivedNote $grn,
        array $headerData,
        array $linesData,
        User $actor
    ): GoodsReceivedNote {
        if (! $grn->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya GRN berstatus Draf yang dapat diperbarui.'],
            ]);
        }

        return DB::transaction(function () use ($grn, $headerData, $linesData, $actor) {
            $grn->update([
                'supplier_delivery_note_no' => $headerData['supplier_delivery_note_no'] ?? $grn->supplier_delivery_note_no,
                'received_at' => $headerData['received_at'] ?? $grn->received_at,
                'notes' => $headerData['notes'] ?? $grn->notes,
            ]);

            // Hapus baris lama dan buat ulang
            $grn->lines()->delete();

            $po = $grn->purchaseOrder;
            $poLines = $po->lines()->get()->keyBy('id');
            $lineNumber = 1;

            foreach ($linesData as $index => $lineItem) {
                $polId = (int) $lineItem['purchase_order_line_id'];
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLines->get($polId);

                if (! $poLine) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.purchase_order_line_id" => ["Baris PO #{$polId} tidak valid untuk PO #{$po->po_number}."],
                    ]);
                }

                $receivedQty = (string) $lineItem['received_qty'];
                $acceptedQty = (string) ($lineItem['accepted_qty'] ?? $receivedQty);
                $rejectedQty = (string) ($lineItem['rejected_qty'] ?? '0.0000');
                $damagedQty = (string) ($lineItem['damaged_qty'] ?? '0.0000');

                $sumAccRej = bcadd($acceptedQty, $rejectedQty, 4);
                if (bccomp($receivedQty, $sumAccRej, 4) !== 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.received_qty" => [
                            "Kuantitas diterima ({$receivedQty}) harus tepat sama dengan jumlah diterima baik ({$acceptedQty}) ditambah ditolak ({$rejectedQty}).",
                        ],
                    ]);
                }

                $tolerancePct = $poLine->over_receipt_tolerance_pct ?? '0.00';
                $toleranceRate = bcdiv((string) $tolerancePct, '100', 4);
                $maxAllowed = bcmul((string) $poLine->ordered_qty, bcadd('1', $toleranceRate, 4), 4);
                $projectedAccepted = bcadd((string) $poLine->accepted_qty, $acceptedQty, 4);

                if (bccomp($projectedAccepted, $maxAllowed, 4) > 0) {
                    throw new OverReceiptToleranceExceededException(
                        "Kuantitas diterima ({$projectedAccepted}) melebihi batas maksimum penerimaan ({$maxAllowed}, toleransi {$tolerancePct}%) untuk baris PO #{$poLine->id}."
                    );
                }

                $uomFactor = (string) ($lineItem['uom_conversion_factor'] ?? $poLine->uom_conversion_factor ?? '1.000000');
                $baseAcceptedQty = bcmul($acceptedQty, $uomFactor, 4);
                $unitCostSnapshot = bccomp($uomFactor, '1.000000', 6) === 0
                    ? (string) $poLine->unit_price
                    : bcdiv((string) $poLine->unit_price, $uomFactor, 4);

                $damageDisposition = ! empty($lineItem['damage_disposition'])
                    ? DamageDispositionEnum::tryFrom((string) $lineItem['damage_disposition'])
                    : null;

                GoodsReceivedNoteLine::query()->create([
                    'tenant_id' => $grn->tenant_id,
                    'goods_received_note_id' => $grn->id,
                    'purchase_order_line_id' => $poLine->id,
                    'line_number' => $lineNumber++,
                    'purchasable_type' => $poLine->purchasable_type,
                    'purchasable_id' => (int) $poLine->purchasable_id,
                    'uom' => $poLine->purchase_uom,
                    'uom_conversion_factor' => $uomFactor,
                    'received_qty' => $receivedQty,
                    'accepted_qty' => $acceptedQty,
                    'rejected_qty' => $rejectedQty,
                    'damaged_qty' => $damagedQty,
                    'damage_disposition' => $damageDisposition,
                    'rejection_reason' => $lineItem['rejection_reason'] ?? null,
                    'base_accepted_qty' => $baseAcceptedQty,
                    'unit_cost_snapshot' => $unitCostSnapshot,
                    'allocated_landed_cost_per_unit' => '0.0000',
                    'effective_unit_cost' => $unitCostSnapshot,
                    'batch_number' => $lineItem['batch_number'] ?? null,
                    'expiry_date' => $lineItem['expiry_date'] ?? null,
                    'storage_location' => $lineItem['storage_location'] ?? null,
                    'notes' => $lineItem['notes'] ?? null,
                ]);
            }

            $this->auditTrailService->log(
                tenantId: (int) $grn->tenant_id,
                action: 'grn.updated',
                userId: $actor->id,
                subjectType: GoodsReceivedNote::class,
                subjectId: $grn->id,
                description: "Draf GRN #{$grn->grn_number} diperbarui.",
                metadata: ['lines_count' => count($linesData)]
            );

            return $grn->fresh(['lines']);
        });
    }

    /**
     * Memposting GRN secara atomik:
     * 1. Kunci PO dan Baris PO dengan SELECT ... FOR UPDATE.
     * 2. Validasi ulang toleransi over-receipt.
     * 3. Catat mutasi stok atomik ke Modul 4 (InventoryLedgerService) dengan Canonical Lock Ordering.
     * 4. Perbarui akumulator PO dan hitung ulang receiving_status PO.
     * 5. Tandai GRN sebagai posted.
     */
    public function postGrn(GoodsReceivedNote $grn, User $actor, ?string $idempotencyKey = null): GoodsReceivedNote
    {
        // Penanganan idempotensi cepat
        if ($grn->isPosted()) {
            if ($idempotencyKey !== null && $grn->idempotency_key === $idempotencyKey) {
                return $grn;
            }

            throw new DomainException("GRN #{$grn->grn_number} sudah diposting sebelumnya.");
        }

        if (! $grn->isDraft()) {
            throw new DomainException("Hanya GRN berstatus Draf yang dapat diposting (Status saat ini: {$grn->status->value}).");
        }

        $tenantId = (int) $grn->tenant_id;

        return DB::transaction(function () use ($grn, $actor, $idempotencyKey, $tenantId) {
            // Langkah 1: Kunci PO dan GRN
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($grn->purchase_order_id);

            /** @var GoodsReceivedNote $lockedGrn */
            $lockedGrn = GoodsReceivedNote::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($grn->id);

            if (! $lockedGrn->isDraft()) {
                throw new DomainException("GRN #{$lockedGrn->grn_number} telah diproses oleh sesi lain.");
            }

            // Langkah 2: Kunci baris PO terurut berdasarkan id ASC
            $poLines = PurchaseOrderLine::query()
                ->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $po->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $grnLines = $lockedGrn->lines()->orderBy('id', 'asc')->get();

            // Langkah 3: Validasi toleransi over-receipt dengan lock aktif
            foreach ($grnLines as $grnLine) {
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLines->get($grnLine->purchase_order_line_id);
                if (! $poLine) {
                    throw new DomainException("Baris PO #{$grnLine->purchase_order_line_id} tidak ditemukan.");
                }

                $tolerancePct = $poLine->over_receipt_tolerance_pct ?? '0.00';
                $toleranceRate = bcdiv((string) $tolerancePct, '100', 4);
                $maxAllowed = bcmul((string) $poLine->ordered_qty, bcadd('1', $toleranceRate, 4), 4);
                $projectedAccepted = bcadd((string) $poLine->accepted_qty, (string) $grnLine->accepted_qty, 4);

                if (bccomp($projectedAccepted, $maxAllowed, 4) > 0) {
                    throw new OverReceiptToleranceExceededException(
                        "Kuantitas diterima ({$projectedAccepted}) melebihi batas maksimum penerimaan ({$maxAllowed}, toleransi {$tolerancePct}%) untuk baris PO #{$poLine->id}."
                    );
                }
            }

            // Langkah 4: Siapkan mutasi batch untuk InventoryLedgerService (M4)
            $movementItems = [];
            $eligibleGrnLines = [];

            foreach ($grnLines as $grnLine) {
                if (bccomp((string) $grnLine->base_accepted_qty, '0', 4) > 0) {
                    $itemType = $grnLine->purchasable_type === 'product' ? 'product' : 'ingredient';

                    $movementItems[] = [
                        'store_id' => (int) $lockedGrn->store_id,
                        'item_type' => $itemType,
                        'item_id' => (int) $grnLine->purchasable_id,
                        'quantity_delta' => (string) $grnLine->base_accepted_qty,
                        'type' => StockMovementType::GoodsReceipt->value,
                        'unit_cost' => (string) $grnLine->effective_unit_cost,
                        'reason' => "Penerimaan barang GRN #{$lockedGrn->grn_number} atas PO #{$po->po_number}",
                        'idempotency_key' => "grn:post:{$tenantId}:{$lockedGrn->id}:{$grnLine->id}",
                        'user_id' => $actor->id,
                    ];

                    $eligibleGrnLines[] = $grnLine;
                }
            }

            // Tulis mutasi stok ke M4 (otoritatif)
            if (! empty($movementItems)) {
                $batchResults = $this->inventoryLedgerService->recordBatchMovements($tenantId, $movementItems);

                foreach ($batchResults as $idx => $res) {
                    $movement = $res['movement'];
                    $targetLine = $eligibleGrnLines[$idx];

                    if ($targetLine->purchasable_type === 'product') {
                        $targetLine->stock_movement_id = $movement->id;
                    } else {
                        $targetLine->ingredient_stock_movement_id = $movement->id;
                    }
                    $targetLine->save();
                }
            }

            // Langkah 5: Perbarui akumulator kuantitas baris PO
            foreach ($grnLines as $grnLine) {
                /** @var PurchaseOrderLine $poLine */
                $poLine = $poLines->get($grnLine->purchase_order_line_id);
                $poLine->received_qty = bcadd((string) $poLine->received_qty, (string) $grnLine->received_qty, 4);
                $poLine->accepted_qty = bcadd((string) $poLine->accepted_qty, (string) $grnLine->accepted_qty, 4);
                $poLine->rejected_qty = bcadd((string) $poLine->rejected_qty, (string) $grnLine->rejected_qty, 4);
                $poLine->save();
            }

            // Langkah 6: Evaluasi status penerimaan PO secara keseluruhan
            $allPolines = PurchaseOrderLine::query()
                ->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $po->id)
                ->get();

            $allFullyReceived = true;
            $hasAnyOverReceived = false;
            $hasAnyAccepted = false;

            foreach ($allPolines as $line) {
                if (bccomp((string) $line->accepted_qty, (string) $line->ordered_qty, 4) < 0) {
                    $allFullyReceived = false;
                }
                if (bccomp((string) $line->accepted_qty, (string) $line->ordered_qty, 4) > 0) {
                    $hasAnyOverReceived = true;
                }
                if (bccomp((string) $line->accepted_qty, '0', 4) > 0) {
                    $hasAnyAccepted = true;
                }
            }

            if ($allFullyReceived) {
                $po->receiving_status = $hasAnyOverReceived
                    ? PoReceivingStatusEnum::OverReceived
                    : PoReceivingStatusEnum::FullyReceived;
            } elseif ($hasAnyAccepted) {
                $po->receiving_status = PoReceivingStatusEnum::PartiallyReceived;
            } else {
                $po->receiving_status = PoReceivingStatusEnum::NotReceived;
            }
            $po->save();

            // Langkah 7: Tandai GRN sebagai Posted
            $lockedGrn->status = GrnStatusEnum::Posted;
            $lockedGrn->posted_at = now();
            $lockedGrn->posted_by_user_id = $actor->id;
            if ($idempotencyKey !== null) {
                $lockedGrn->idempotency_key = $idempotencyKey;
            }
            $lockedGrn->save();

            // Langkah 8: Catat analitik performa lead time & histori harga pemasok (Fase 8)
            $this->performanceService->recordGrnReceipt($lockedGrn, $po);

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'grn.posted',
                userId: $actor->id,
                subjectType: GoodsReceivedNote::class,
                subjectId: $lockedGrn->id,
                description: "GRN #{$lockedGrn->grn_number} berhasil diposting. Stok masuk telah dicatat ke inventori.",
                metadata: [
                    'po_number' => $po->po_number,
                    'po_receiving_status' => $po->receiving_status->value,
                    'received_lines_count' => count($movementItems),
                ]
            );

            // Emisi outbox akuntansi ke Modul 7 (PR-PUR-01)
            app(InventoryAccountingService::class)->recordGrnPosted($lockedGrn);

            return $lockedGrn->fresh(['lines', 'purchaseOrder']);
        }, attempts: 3);
    }

    /**
     * Membalikkan (Reverse) GRN yang sudah diposting.
     * Membuat mutasi pembalik keluar di M4, merestorasi akumulator PO,
     * dan mengubah status GRN menjadi reversed.
     */
    public function reverseGrn(GoodsReceivedNote $grn, User $actor, string $reversalReason): GoodsReceivedNote
    {
        if (! $grn->isPosted()) {
            throw new DomainException('Hanya GRN berstatus Diposting (Posted) yang dapat dibalik.');
        }

        if (trim($reversalReason) === '') {
            throw ValidationException::withMessages([
                'reversal_reason' => ['Alasan pembalikan GRN wajib diisi.'],
            ]);
        }

        $tenantId = (int) $grn->tenant_id;
        $storeId = (int) $grn->store_id;

        return DB::transaction(function () use ($grn, $actor, $reversalReason, $tenantId, $storeId) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($grn->purchase_order_id);

            /** @var GoodsReceivedNote $lockedGrn */
            $lockedGrn = GoodsReceivedNote::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($grn->id);

            if (! $lockedGrn->isPosted()) {
                throw new DomainException("GRN #{$lockedGrn->grn_number} telah dibalik atau diubah oleh sesi lain.");
            }

            $poLines = PurchaseOrderLine::query()
                ->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $po->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $grnLines = $lockedGrn->lines()->orderBy('id', 'asc')->get();

            // Siapkan mutasi pengeluaran stok pembalik (goods_receipt_reversal)
            $reversalMovements = [];
            foreach ($grnLines as $grnLine) {
                if (bccomp((string) $grnLine->base_accepted_qty, '0', 4) > 0) {
                    $itemType = $grnLine->purchasable_type === 'product' ? 'product' : 'ingredient';

                    $reversalMovements[] = [
                        'store_id' => $storeId,
                        'item_type' => $itemType,
                        'item_id' => (int) $grnLine->purchasable_id,
                        'quantity_delta' => bcmul((string) $grnLine->base_accepted_qty, '-1', 4),
                        'type' => StockMovementType::GoodsReceiptReversal->value,
                        'unit_cost' => (string) $grnLine->effective_unit_cost,
                        'reason' => "Pembalikan GRN #{$lockedGrn->grn_number}: {$reversalReason}",
                        'idempotency_key' => "grn:reverse:{$tenantId}:{$lockedGrn->id}:{$grnLine->id}",
                        'user_id' => $actor->id,
                    ];
                }
            }

            // Eksekusi mutasi keluar di M4 (jika stok kurang, Invariant INV-M4-05 akan melempar ValidationException)
            if (! empty($reversalMovements)) {
                $this->inventoryLedgerService->recordBatchMovements($tenantId, $reversalMovements);
            }

            // Kembalikan akumulator pada baris PO
            foreach ($grnLines as $grnLine) {
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLines->get($grnLine->purchase_order_line_id);
                if ($poLine) {
                    $newRec = bcsub((string) $poLine->received_qty, (string) $grnLine->received_qty, 4);
                    $newAcc = bcsub((string) $poLine->accepted_qty, (string) $grnLine->accepted_qty, 4);
                    $newRej = bcsub((string) $poLine->rejected_qty, (string) $grnLine->rejected_qty, 4);

                    $poLine->received_qty = bccomp($newRec, '0', 4) > 0 ? $newRec : '0.0000';
                    $poLine->accepted_qty = bccomp($newAcc, '0', 4) > 0 ? $newAcc : '0.0000';
                    $poLine->rejected_qty = bccomp($newRej, '0', 4) > 0 ? $newRej : '0.0000';
                    $poLine->save();
                }
            }

            // Evaluasi ulang receiving_status PO
            $allPolines = PurchaseOrderLine::query()
                ->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $po->id)
                ->get();

            $allFullyReceived = true;
            $hasAnyOverReceived = false;
            $hasAnyAccepted = false;

            foreach ($allPolines as $line) {
                if (bccomp((string) $line->accepted_qty, (string) $line->ordered_qty, 4) < 0) {
                    $allFullyReceived = false;
                }
                if (bccomp((string) $line->accepted_qty, (string) $line->ordered_qty, 4) > 0) {
                    $hasAnyOverReceived = true;
                }
                if (bccomp((string) $line->accepted_qty, '0', 4) > 0) {
                    $hasAnyAccepted = true;
                }
            }

            if ($allFullyReceived) {
                $po->receiving_status = $hasAnyOverReceived
                    ? PoReceivingStatusEnum::OverReceived
                    : PoReceivingStatusEnum::FullyReceived;
            } elseif ($hasAnyAccepted) {
                $po->receiving_status = PoReceivingStatusEnum::PartiallyReceived;
            } else {
                $po->receiving_status = PoReceivingStatusEnum::NotReceived;
            }
            $po->save();

            // Tandai GRN sebagai reversed
            $lockedGrn->status = GrnStatusEnum::Reversed;
            $lockedGrn->reversed_at = now();
            $lockedGrn->reversed_by_user_id = $actor->id;
            $lockedGrn->reversal_reason = $reversalReason;
            $lockedGrn->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'grn.reversed',
                userId: $actor->id,
                subjectType: GoodsReceivedNote::class,
                subjectId: $lockedGrn->id,
                description: "GRN #{$lockedGrn->grn_number} dibalik. Stok keluar pembalik dicatat ke inventori.",
                metadata: [
                    'po_number' => $po->po_number,
                    'reversal_reason' => $reversalReason,
                ]
            );

            // Emisi outbox akuntansi ke Modul 7 (PR-PUR-02)
            app(InventoryAccountingService::class)->recordGrnReversed($lockedGrn, $reversalReason);

            return $lockedGrn->fresh(['lines', 'purchaseOrder']);
        }, attempts: 3);
    }

    /**
     * Membatalkan draf GRN.
     */
    public function cancelDraftGrn(GoodsReceivedNote $grn, User $actor, string $reason): GoodsReceivedNote
    {
        if (! $grn->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya GRN berstatus Draf yang dapat dibatalkan.'],
            ]);
        }

        $grn->status = GrnStatusEnum::Cancelled;
        $grn->notes = ($grn->notes ? $grn->notes."\n" : '')."Dibatalkan oleh {$actor->name}: {$reason}";
        $grn->save();

        $this->auditTrailService->log(
            tenantId: (int) $grn->tenant_id,
            action: 'grn.cancelled',
            userId: $actor->id,
            subjectType: GoodsReceivedNote::class,
            subjectId: $grn->id,
            description: "Draf GRN #{$grn->grn_number} dibatalkan.",
            metadata: ['reason' => $reason]
        );

        return $grn;
    }
}
