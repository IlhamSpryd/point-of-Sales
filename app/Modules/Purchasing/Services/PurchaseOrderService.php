<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\PoInvoicingStatusEnum;
use App\Enums\PoReceivingStatusEnum;
use App\Enums\PurchaseOrderSourceTypeEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\PurchaseRequestFulfillmentStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseOrderLinePrAllocation;
use App\Modules\Purchasing\Models\PurchaseOrderRevision;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected PurchaseApprovalService $approvalService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Konversi Purchase Request (PR) yang disetujui menjadi Purchase Order (PO).
     * Mendukung konversi parsial atau penuh (AT-04).
     *
     * @param  array<string, mixed>  $headerData
     * @param  array<int, array<string, mixed>>  $allocations
     */
    public function createFromPurchaseRequests(
        array $headerData,
        array $allocations,
        User $actor,
        ?string $idempotencyKey = null
    ): PurchaseOrder {
        $tenantId = (int) $actor->tenant_id;
        $storeId = (int) ($headerData['store_id'] ?? 0);
        $supplierId = (int) ($headerData['supplier_id'] ?? 0);

        if ($storeId <= 0) {
            throw ValidationException::withMessages(['store_id' => ['Cabang/Toko tujuan wajib dipilih.']]);
        }

        if ($supplierId <= 0) {
            throw ValidationException::withMessages(['supplier_id' => ['Pemasok wajib dipilih.']]);
        }

        if (empty($allocations)) {
            throw ValidationException::withMessages(['allocations' => ['Minimal harus ada 1 alokasi baris PR untuk membuat PO.']]);
        }

        // Validasi Pemasok harus Active
        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()->where('tenant_id', $tenantId)->find($supplierId);
        if (! $supplier || $supplier->status !== SupplierStatusEnum::Active) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Pemasok tidak ditemukan atau statusnya tidak aktif.'],
            ]);
        }

        if ($idempotencyKey) {
            $existing = PurchaseOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing->load(['lines']);
            }
        }

        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();

        return DB::transaction(function () use ($tenantId, $storeId, $supplierId, $headerData, $allocations, $actor, $idempotencyKey, $supplier) {
            // 1. Kunci baris PR pesimistis (ORDER BY id ASC FOR UPDATE)
            $prLineIds = array_map(fn ($a) => (int) $a['purchase_request_line_id'], $allocations);
            /** @var Collection<int, PurchaseRequestLine> $lockedPrLines */
            $lockedPrLines = PurchaseRequestLine::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $prLineIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 2. Validasi sisa kuantitas pada masing-masing baris PR
            foreach ($allocations as $index => $alloc) {
                $prLineId = (int) $alloc['purchase_request_line_id'];
                $prLine = $lockedPrLines->get($prLineId);

                if (! $prLine) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.purchase_request_line_id" => ["Baris PR #{$prLineId} tidak ditemukan."],
                    ]);
                }

                $parentPr = $prLine->purchaseRequest;
                if ($parentPr->status !== PurchaseRequestStatusEnum::Approved) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}" => ["Baris PR #{$prLineId} berasal dari PR yang belum disetujui (Approved)."],
                    ]);
                }

                $allocQty = (string) $alloc['ordered_qty'];
                if (bccomp($allocQty, '0', 4) <= 0) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.ordered_qty" => ['Kuantitas alokasi harus lebih besar dari 0.'],
                    ]);
                }

                $remQty = $prLine->remainingOrderableQty();
                if (bccomp($allocQty, $remQty, 4) > 0) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.ordered_qty" => [
                            "Kuantitas alokasi ({$allocQty}) melampaui sisa kuantitas yang dapat dipesan ({$remQty}) pada baris PR #{$prLineId}.",
                        ],
                    ]);
                }
            }

            // 3. Buat nomor dokumen unik atomik dari sekuens
            $orderDateStr = (string) ($headerData['order_date'] ?? now()->toDateString());
            $periodKey = Carbon::parse($orderDateStr)->format('Ym');
            $poNumber = $this->sequenceService->nextPurchaseOrderNumber($tenantId, $storeId, $periodKey);

            // 4. Hitung total baris PO dengan BCMath
            $subtotalAmount = '0.00';
            $discountAmount = '0.00';
            $taxAmount = '0.00';
            $totalAmount = '0.00';

            $calculatedLines = [];
            foreach ($allocations as $alloc) {
                $prLine = $lockedPrLines->get((int) $alloc['purchase_request_line_id']);
                $financials = $this->calculateLineFinancials(
                    orderedQty: (string) $alloc['ordered_qty'],
                    unitPrice: (string) ($alloc['unit_price'] ?? $prLine->estimated_unit_price),
                    conversionFactor: (string) ($alloc['uom_conversion_factor'] ?? '1.0000'),
                    discountPct: (string) ($alloc['discount_pct'] ?? '0.0000'),
                    discountAmount: (string) ($alloc['discount_amount'] ?? '0.00'),
                    taxRate: (string) ($alloc['tax_rate'] ?? '0.0000'),
                    taxInclusive: (bool) ($alloc['tax_inclusive'] ?? false)
                );

                $subtotalAmount = bcadd($subtotalAmount, $financials['gross_amount'], 2);
                $discountAmount = bcadd($discountAmount, $financials['discount_amount'], 2);
                $taxAmount = bcadd($taxAmount, $financials['tax_amount'], 2);
                $totalAmount = bcadd($totalAmount, $financials['line_total'], 2);

                $calculatedLines[] = [
                    'pr_line' => $prLine,
                    'alloc_qty' => (string) $alloc['ordered_qty'],
                    'purchase_uom' => (string) ($alloc['purchase_uom'] ?? $prLine->uom),
                    'over_receipt_tolerance_pct' => (string) ($alloc['over_receipt_tolerance_pct'] ?? '0.0000'),
                    'notes' => $alloc['notes'] ?? null,
                    'financials' => $financials,
                ];
            }

            $additionalCost = isset($headerData['additional_cost_estimate']) ? (string) $headerData['additional_cost_estimate'] : '0.00';
            $finalTotalAmount = bcadd($totalAmount, $additionalCost, 2);

            // 5. Simpan PurchaseOrder
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'supplier_id' => $supplierId,
                'po_number' => $poNumber,
                'revision_number' => 0,
                'source_type' => PurchaseOrderSourceTypeEnum::FromPr,
                'order_date' => $orderDateStr,
                'expected_delivery_date' => $headerData['expected_delivery_date'] ?? null,
                'payment_terms_days' => max(0, (int) ($headerData['payment_terms_days'] ?? $supplier->payment_terms_days)),
                'currency_code' => strtoupper((string) ($headerData['currency_code'] ?? 'IDR')),
                'exchange_rate' => (string) ($headerData['exchange_rate'] ?? '1.0000'),
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'additional_cost_estimate' => $additionalCost,
                'total_amount' => $finalTotalAmount,
                'status' => PurchaseOrderStatusEnum::Draft,
                'receiving_status' => PoReceivingStatusEnum::NotReceived,
                'invoicing_status' => PoInvoicingStatusEnum::NotInvoiced,
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'notes' => $headerData['notes'] ?? null,
            ]);

            // 6. Simpan baris PO dan alokasi ke baris PR
            $affectedPrIds = [];
            foreach ($calculatedLines as $item) {
                $prLine = $item['pr_line'];
                $f = $item['financials'];

                /** @var PurchaseOrderLine $poLine */
                $poLine = PurchaseOrderLine::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $po->id,
                    'purchasable_type' => $prLine->purchasable_type,
                    'purchasable_id' => $prLine->purchasable_id,
                    'purchase_uom' => $item['purchase_uom'],
                    'uom_conversion_factor' => $f['uom_conversion_factor'],
                    'ordered_qty' => $f['ordered_qty'],
                    'base_ordered_qty' => $f['base_ordered_qty'],
                    'received_qty' => '0.0000',
                    'accepted_qty' => '0.0000',
                    'rejected_qty' => '0.0000',
                    'returned_qty' => '0.0000',
                    'invoiced_qty' => '0.0000',
                    'unit_price' => $f['unit_price'],
                    'discount_pct' => $f['discount_pct'],
                    'discount_amount' => $f['discount_amount'],
                    'tax_rate' => $f['tax_rate'],
                    'tax_inclusive' => $f['tax_inclusive'],
                    'tax_amount' => $f['tax_amount'],
                    'line_total' => $f['line_total'],
                    'over_receipt_tolerance_pct' => $item['over_receipt_tolerance_pct'],
                    'notes' => $item['notes'],
                ]);

                // Simpan baris alokasi many-to-many
                PurchaseOrderLinePrAllocation::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_order_line_id' => $poLine->id,
                    'purchase_request_line_id' => $prLine->id,
                    'allocated_qty' => $item['alloc_qty'],
                ]);

                // Update converted_to_po_qty pada baris PR
                $prLine->converted_to_po_qty = bcadd((string) $prLine->converted_to_po_qty, $item['alloc_qty'], 4);
                $prLine->save();

                $affectedPrIds[$prLine->purchase_request_id] = true;
            }

            // 7. Evaluasi dan perbarui fulfillment_status pada seluruh PR yang terdampak
            foreach (array_keys($affectedPrIds) as $prId) {
                $this->updatePrFulfillmentStatus((int) $prId, $tenantId);
            }

            // 8. Catat Audit Log
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'po.created_from_pr',
                userId: $actor->id,
                storeId: $storeId,
                subjectType: PurchaseOrder::class,
                subjectId: $po->id,
                description: "Pesanan Pembelian {$po->po_number} berhasil dibuat dari alokasi PR.",
                metadata: [
                    'po_number' => $po->po_number,
                    'pr_ids' => array_keys($affectedPrIds),
                    'total_amount' => $finalTotalAmount,
                ]
            );

            return $po;
        });
    }

    /**
     * Pembuatan PO Langsung (Direct PO) tanpa PR (AT-05 & ADR-M5-02).
     *
     * @param  array<string, mixed>  $headerData
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createDirectPurchaseOrder(
        array $headerData,
        array $items,
        User $actor,
        ?string $idempotencyKey = null
    ): PurchaseOrder {
        $tenantId = (int) $actor->tenant_id;
        $storeId = (int) ($headerData['store_id'] ?? 0);
        $supplierId = (int) ($headerData['supplier_id'] ?? 0);

        if ($storeId <= 0) {
            throw ValidationException::withMessages(['store_id' => ['Cabang/Toko tujuan wajib dipilih.']]);
        }

        if ($supplierId <= 0) {
            throw ValidationException::withMessages(['supplier_id' => ['Pemasok wajib dipilih.']]);
        }

        if (empty($items)) {
            throw ValidationException::withMessages(['items' => ['Minimal harus ada 1 item pesanan.']]);
        }

        // Kontrol Kebijakan Tenant: purchasing.po.require_pr (ADR-M5-02)
        $setting = Setting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', 'purchasing.po.require_pr')
            ->first();

        $requirePr = $setting ? filter_var($setting->value, FILTER_VALIDATE_BOOLEAN) : false;
        if ($requirePr) {
            throw ValidationException::withMessages([
                'policy' => ['Kebijakan tenant mewajibkan seluruh Pesanan Pembelian dibuat melalui Permintaan Pembelian (PR) yang disetujui.'],
            ]);
        }

        // Kontrol Izin Otorisasi Server-Side: purchasing.po.create_direct
        $hasDirectPermission = $actor->hasPermission('purchasing.po.create_direct')
            || $actor->role?->name === 'Owner'
            || (bool) $actor->role?->is_admin;

        if (! $hasDirectPermission) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin (purchasing.po.create_direct) untuk membuat Pesanan Pembelian langsung tanpa PR.'],
            ]);
        }

        // Validasi Pemasok harus Active
        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()->where('tenant_id', $tenantId)->find($supplierId);
        if (! $supplier || $supplier->status !== SupplierStatusEnum::Active) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Pemasok tidak ditemukan atau statusnya tidak aktif.'],
            ]);
        }

        if ($idempotencyKey) {
            $existing = PurchaseOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing->load(['lines']);
            }
        }

        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();

        return DB::transaction(function () use ($tenantId, $storeId, $supplierId, $headerData, $items, $actor, $idempotencyKey, $supplier) {
            $orderDateStr = (string) ($headerData['order_date'] ?? now()->toDateString());
            $periodKey = Carbon::parse($orderDateStr)->format('Ym');
            $poNumber = $this->sequenceService->nextPurchaseOrderNumber($tenantId, $storeId, $periodKey);

            $subtotalAmount = '0.00';
            $discountAmount = '0.00';
            $taxAmount = '0.00';
            $totalAmount = '0.00';

            $calculatedLines = [];
            foreach ($items as $index => $item) {
                $orderedQty = (string) ($item['ordered_qty'] ?? '0');
                if (bccomp($orderedQty, '0', 4) <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.ordered_qty" => ['Kuantitas pesanan harus lebih besar dari 0.'],
                    ]);
                }

                $financials = $this->calculateLineFinancials(
                    orderedQty: $orderedQty,
                    unitPrice: (string) ($item['unit_price'] ?? '0.0000'),
                    conversionFactor: (string) ($item['uom_conversion_factor'] ?? '1.0000'),
                    discountPct: (string) ($item['discount_pct'] ?? '0.0000'),
                    discountAmount: (string) ($item['discount_amount'] ?? '0.00'),
                    taxRate: (string) ($item['tax_rate'] ?? '0.0000'),
                    taxInclusive: (bool) ($item['tax_inclusive'] ?? false)
                );

                $subtotalAmount = bcadd($subtotalAmount, $financials['gross_amount'], 2);
                $discountAmount = bcadd($discountAmount, $financials['discount_amount'], 2);
                $taxAmount = bcadd($taxAmount, $financials['tax_amount'], 2);
                $totalAmount = bcadd($totalAmount, $financials['line_total'], 2);

                $calculatedLines[] = [
                    'purchasable_type' => strtolower(trim((string) $item['purchasable_type'])),
                    'purchasable_id' => (int) $item['purchasable_id'],
                    'purchase_uom' => strtoupper(trim((string) $item['purchase_uom'])),
                    'over_receipt_tolerance_pct' => (string) ($item['over_receipt_tolerance_pct'] ?? '0.0000'),
                    'notes' => $item['notes'] ?? null,
                    'financials' => $financials,
                ];
            }

            $additionalCost = isset($headerData['additional_cost_estimate']) ? (string) $headerData['additional_cost_estimate'] : '0.00';
            $finalTotalAmount = bcadd($totalAmount, $additionalCost, 2);

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'supplier_id' => $supplierId,
                'po_number' => $poNumber,
                'revision_number' => 0,
                'source_type' => PurchaseOrderSourceTypeEnum::Direct,
                'order_date' => $orderDateStr,
                'expected_delivery_date' => $headerData['expected_delivery_date'] ?? null,
                'payment_terms_days' => max(0, (int) ($headerData['payment_terms_days'] ?? $supplier->payment_terms_days)),
                'currency_code' => strtoupper((string) ($headerData['currency_code'] ?? 'IDR')),
                'exchange_rate' => (string) ($headerData['exchange_rate'] ?? '1.0000'),
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'additional_cost_estimate' => $additionalCost,
                'total_amount' => $finalTotalAmount,
                'status' => PurchaseOrderStatusEnum::Draft,
                'receiving_status' => PoReceivingStatusEnum::NotReceived,
                'invoicing_status' => PoInvoicingStatusEnum::NotInvoiced,
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'notes' => $headerData['notes'] ?? null,
            ]);

            foreach ($calculatedLines as $item) {
                $f = $item['financials'];
                PurchaseOrderLine::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $po->id,
                    'purchasable_type' => $item['purchasable_type'],
                    'purchasable_id' => $item['purchasable_id'],
                    'purchase_uom' => $item['purchase_uom'],
                    'uom_conversion_factor' => $f['uom_conversion_factor'],
                    'ordered_qty' => $f['ordered_qty'],
                    'base_ordered_qty' => $f['base_ordered_qty'],
                    'received_qty' => '0.0000',
                    'accepted_qty' => '0.0000',
                    'rejected_qty' => '0.0000',
                    'returned_qty' => '0.0000',
                    'invoiced_qty' => '0.0000',
                    'unit_price' => $f['unit_price'],
                    'discount_pct' => $f['discount_pct'],
                    'discount_amount' => $f['discount_amount'],
                    'tax_rate' => $f['tax_rate'],
                    'tax_inclusive' => $f['tax_inclusive'],
                    'tax_amount' => $f['tax_amount'],
                    'line_total' => $f['line_total'],
                    'over_receipt_tolerance_pct' => $item['over_receipt_tolerance_pct'],
                    'notes' => $item['notes'],
                ]);
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'po.created_direct',
                userId: $actor->id,
                storeId: $storeId,
                subjectType: PurchaseOrder::class,
                subjectId: $po->id,
                description: "Pesanan Pembelian Langsung (Direct PO) {$po->po_number} berhasil dibuat.",
                metadata: [
                    'po_number' => $po->po_number,
                    'total_amount' => $finalTotalAmount,
                ]
            );

            return $po;
        });
    }

    /**
     * Mengajukan PO ke alur persetujuan.
     */
    public function submitPurchaseOrder(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if (! $po->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['PO hanya dapat diajukan ke persetujuan dari status Draf.'],
            ]);
        }

        return DB::transaction(function () use ($po, $actor) {
            $approvalReq = $this->approvalService->initiateApproval($po, $actor);

            if ($approvalReq === null) {
                // Auto-approved karena tidak ada aturan atau nilai di bawah ambang
                $po->status = PurchaseOrderStatusEnum::Approved;
                $po->approved_at = now();
                $po->approved_by_user_id = $actor->id;
            } else {
                $po->status = PurchaseOrderStatusEnum::PendingApproval;
            }
            $po->save();

            $this->auditTrailService->log(
                tenantId: (int) $po->tenant_id,
                action: 'po.submitted',
                userId: $actor->id,
                storeId: (int) $po->store_id,
                subjectType: PurchaseOrder::class,
                subjectId: (int) $po->id,
                description: "PO {$po->po_number} diajukan. Status: {$po->status->label()}.",
                metadata: ['status' => $po->status->value]
            );

            return $po;
        });
    }

    /**
     * Menerbitkan PO ke pemasok (Release PO).
     * Mengunci komitmen dan mengizinkan pembuatan GRN.
     */
    public function releasePurchaseOrder(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if (! $po->canBeReleased()) {
            throw ValidationException::withMessages([
                'status' => ['PO harus berstatus Disetujui (Approved) untuk dapat diterbitkan (Released).'],
            ]);
        }

        // Pemasok harus tetap active
        if ($po->supplier->status !== SupplierStatusEnum::Active) {
            throw ValidationException::withMessages([
                'supplier_id' => ['PO tidak dapat dirilis karena status pemasok sedang tidak aktif.'],
            ]);
        }

        return DB::transaction(function () use ($po, $actor) {
            $po->status = PurchaseOrderStatusEnum::Released;
            $po->released_at = now();
            $po->released_by_user_id = $actor->id;
            $po->save();

            $this->auditTrailService->log(
                tenantId: (int) $po->tenant_id,
                action: 'po.released',
                userId: $actor->id,
                storeId: (int) $po->store_id,
                subjectType: PurchaseOrder::class,
                subjectId: (int) $po->id,
                description: "Pesanan Pembelian {$po->po_number} resmi diterbitkan (Released) ke pemasok.",
                metadata: ['released_at' => $po->released_at->toIso8601String()]
            );

            return $po;
        });
    }

    /**
     * Mengamandemen PO yang telah berstatus released (Section 6.3 & Section 15.1).
     *
     * @param  array<int, array<string, mixed>>  $newLinesData
     */
    public function amendPurchaseOrder(
        PurchaseOrder $po,
        array $newLinesData,
        string $reason,
        User $actor
    ): PurchaseOrder {
        if (! $po->canBeAmended()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya PO yang berstatus Diterbitkan (Released) yang dapat diamandemen.'],
            ]);
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['Alasan amandemen wajib diisi minimal 10 karakter.'],
            ]);
        }

        return DB::transaction(function () use ($po, $newLinesData, $reason, $actor) {
            /** @var PurchaseOrder $lockedPo */
            $lockedPo = PurchaseOrder::query()
                ->where('tenant_id', $po->tenant_id)
                ->lockForUpdate()
                ->findOrFail($po->id);

            // Kunci baris PO terurut
            /** @var Collection<int, PurchaseOrderLine> $lockedLines */
            $lockedLines = PurchaseOrderLine::query()
                ->where('tenant_id', $po->tenant_id)
                ->where('purchase_order_id', $po->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 1. Validasi baris amandemen: new_ordered_qty >= max(accepted_qty, invoiced_qty)
            $linesPayloadIndexed = [];
            foreach ($newLinesData as $item) {
                $lineId = (int) ($item['id'] ?? 0);
                if (! $lockedLines->has($lineId)) {
                    throw ValidationException::withMessages([
                        'lines' => ["Baris PO #{$lineId} tidak ditemukan."],
                    ]);
                }
                $linesPayloadIndexed[$lineId] = $item;
            }

            foreach ($lockedLines as $lineId => $line) {
                if (isset($linesPayloadIndexed[$lineId])) {
                    $item = $linesPayloadIndexed[$lineId];
                    $newQty = (string) ($item['ordered_qty'] ?? $line->ordered_qty);
                    $minAllowed = bccomp((string) $line->accepted_qty, (string) $line->invoiced_qty, 4) >= 0
                        ? (string) $line->accepted_qty
                        : (string) $line->invoiced_qty;

                    if (bccomp($newQty, $minAllowed, 4) < 0) {
                        throw ValidationException::withMessages([
                            'lines' => [
                                "Kuantitas baru ({$newQty}) pada baris #{$lineId} tidak boleh lebih kecil dari kuantitas yang sudah diterima/ditagihkan ({$minAllowed}).",
                            ],
                        ]);
                    }
                }
            }

            // 2. Buat Snapshot ke purchase_order_revisions (Append-only)
            $revisionNumber = $lockedPo->revision_number + 1;
            $snapshotData = [
                'header' => $lockedPo->toArray(),
                'lines' => $lockedLines->map(fn ($l) => $l->toArray())->all(),
            ];

            // 3. Terapkan perubahan pada baris dan hitung ulang total
            $subtotalAmount = '0.00';
            $discountAmount = '0.00';
            $taxAmount = '0.00';
            $totalAmount = '0.00';

            foreach ($lockedLines as $lineId => $line) {
                if (isset($linesPayloadIndexed[$lineId])) {
                    $item = $linesPayloadIndexed[$lineId];
                    $newQty = (string) ($item['ordered_qty'] ?? $line->ordered_qty);
                    $newPrice = (string) ($item['unit_price'] ?? $line->unit_price);
                    $newDiscPct = (string) ($item['discount_pct'] ?? $line->discount_pct);
                    $newTaxRate = (string) ($item['tax_rate'] ?? $line->tax_rate);

                    $financials = $this->calculateLineFinancials(
                        orderedQty: $newQty,
                        unitPrice: $newPrice,
                        conversionFactor: (string) $line->uom_conversion_factor,
                        discountPct: $newDiscPct,
                        discountAmount: '0.00',
                        taxRate: $newTaxRate,
                        taxInclusive: (bool) $line->tax_inclusive
                    );

                    $line->ordered_qty = $financials['ordered_qty'];
                    $line->base_ordered_qty = $financials['base_ordered_qty'];
                    $line->unit_price = $financials['unit_price'];
                    $line->discount_pct = $financials['discount_pct'];
                    $line->discount_amount = $financials['discount_amount'];
                    $line->tax_rate = $financials['tax_rate'];
                    $line->tax_amount = $financials['tax_amount'];
                    $line->line_total = $financials['line_total'];
                    $line->save();

                    $subtotalAmount = bcadd($subtotalAmount, $financials['gross_amount'], 2);
                    $discountAmount = bcadd($discountAmount, $financials['discount_amount'], 2);
                    $taxAmount = bcadd($taxAmount, $financials['tax_amount'], 2);
                    $totalAmount = bcadd($totalAmount, $financials['line_total'], 2);
                } else {
                    $gross = bcmul((string) $line->ordered_qty, (string) $line->unit_price, 2);
                    $subtotalAmount = bcadd($subtotalAmount, $gross, 2);
                    $discountAmount = bcadd($discountAmount, (string) $line->discount_amount, 2);
                    $taxAmount = bcadd($taxAmount, (string) $line->tax_amount, 2);
                    $totalAmount = bcadd($totalAmount, (string) $line->line_total, 2);
                }
            }

            $finalTotalAmount = bcadd($totalAmount, (string) $lockedPo->additional_cost_estimate, 2);

            // Simpan riwayat revisi
            PurchaseOrderRevision::query()->create([
                'tenant_id' => $lockedPo->tenant_id,
                'purchase_order_id' => $lockedPo->id,
                'revision_number' => $revisionNumber,
                'amended_by_user_id' => $actor->id,
                'amended_at' => now(),
                'reason' => $reason,
                'snapshot_data' => $snapshotData,
                'total_amount_before' => $lockedPo->total_amount,
                'total_amount_after' => $finalTotalAmount,
            ]);

            // Update header PO
            $lockedPo->revision_number = $revisionNumber;
            $lockedPo->subtotal_amount = $subtotalAmount;
            $lockedPo->discount_amount = $discountAmount;
            $lockedPo->tax_amount = $taxAmount;
            $lockedPo->total_amount = $finalTotalAmount;
            $lockedPo->status = PurchaseOrderStatusEnum::Released; // Kembali ke status released dengan revisi baru
            $lockedPo->save();

            $this->auditTrailService->log(
                tenantId: (int) $lockedPo->tenant_id,
                action: 'po.amended',
                userId: $actor->id,
                storeId: (int) $lockedPo->store_id,
                subjectType: PurchaseOrder::class,
                subjectId: (int) $lockedPo->id,
                description: "Pesanan Pembelian {$lockedPo->po_number} diamandemen ke Revisi {$revisionNumber}.",
                metadata: [
                    'revision_number' => $revisionNumber,
                    'reason' => $reason,
                    'amount_before' => $lockedPo->getOriginal('total_amount'),
                    'amount_after' => $finalTotalAmount,
                ]
            );

            return $lockedPo;
        });
    }

    /**
     * Membatalkan PO (Cancel PO).
     * Hanya diizinkan jika belum ada GRN dan belum ada Invoice.
     * Mengembalikan converted_to_po_qty pada baris PR jika berasal dari PR.
     */
    public function cancelPurchaseOrder(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        if (! $po->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['PO yang sudah memiliki histori penerimaan barang atau penagihan faktur tidak dapat dibatalkan.'],
            ]);
        }

        return DB::transaction(function () use ($po, $actor, $reason) {
            $po->status = PurchaseOrderStatusEnum::Cancelled;
            $po->cancellation_reason = $reason;
            $po->save();

            // Jika berasal dari PR, batalkan alokasi kuantitas pada PR line
            if ($po->source_type === PurchaseOrderSourceTypeEnum::FromPr) {
                $allocations = PurchaseOrderLinePrAllocation::query()
                    ->where('tenant_id', $po->tenant_id)
                    ->whereIn('purchase_order_line_id', $po->lines()->pluck('id'))
                    ->get();

                $affectedPrIds = [];
                foreach ($allocations as $alloc) {
                    $prLine = $alloc->purchaseRequestLine;
                    $newConverted = bcsub((string) $prLine->converted_to_po_qty, (string) $alloc->allocated_qty, 4);
                    $prLine->converted_to_po_qty = bccomp($newConverted, '0', 4) > 0 ? $newConverted : '0.0000';
                    $prLine->save();

                    $affectedPrIds[$prLine->purchase_request_id] = true;
                }

                // Update kembali status fulfillment PR
                foreach (array_keys($affectedPrIds) as $prId) {
                    $this->updatePrFulfillmentStatus((int) $prId, (int) $po->tenant_id);
                }
            }

            $this->auditTrailService->log(
                tenantId: (int) $po->tenant_id,
                action: 'po.cancelled',
                userId: $actor->id,
                storeId: (int) $po->store_id,
                subjectType: PurchaseOrder::class,
                subjectId: (int) $po->id,
                description: "Pesanan Pembelian {$po->po_number} dibatalkan.",
                metadata: ['reason' => $reason]
            );

            return $po;
        });
    }

    /**
     * Hitung nilai moneter baris PO dengan presisi BCMath (Section 5.3).
     *
     * @return array<string, mixed>
     */
    public function calculateLineFinancials(
        string $orderedQty,
        string $unitPrice,
        string $conversionFactor = '1.0000',
        string $discountPct = '0.0000',
        string $discountAmount = '0.00',
        string $taxRate = '0.0000',
        bool $taxInclusive = false
    ): array {
        // Base Ordered Qty = Ordered Qty * Conversion Factor
        $baseOrderedQty = bcmul($orderedQty, $conversionFactor, 4);

        // Gross Line = round(Q * P, 2)
        $grossRaw = bcmul($orderedQty, $unitPrice, 4);
        $grossAmount = bcadd($grossRaw, '0', 2);

        // Discount
        if (bccomp($discountPct, '0', 4) > 0) {
            $discRate = bcdiv($discountPct, '100', 4);
            $effectiveDiscount = bcmul($grossAmount, $discRate, 2);
        } else {
            $effectiveDiscount = bcadd($discountAmount, '0', 2);
        }

        // Tax & Line Total
        if (! $taxInclusive) {
            // Pajak Eksklusif
            $taxableBase = bcsub($grossAmount, $effectiveDiscount, 2);
            if (bccomp($taxableBase, '0', 2) < 0) {
                $taxableBase = '0.00';
            }

            $taxRateDecimal = bcdiv($taxRate, '100', 4);
            $taxAmount = bcmul($taxableBase, $taxRateDecimal, 2);
            $lineTotal = bcadd($taxableBase, $taxAmount, 2);
        } else {
            // Pajak Inklusif
            $netTotal = bcsub($grossAmount, $effectiveDiscount, 2);
            if (bccomp($netTotal, '0', 2) < 0) {
                $netTotal = '0.00';
            }

            if (bccomp($taxRate, '0', 4) > 0) {
                $divisor = bcadd('1', bcdiv($taxRate, '100', 4), 4);
                $taxableBase = bcdiv($netTotal, $divisor, 2);
                $taxAmount = bcsub($netTotal, $taxableBase, 2);
            } else {
                $taxableBase = $netTotal;
                $taxAmount = '0.00';
            }

            $lineTotal = $netTotal;
        }

        return [
            'ordered_qty' => $orderedQty,
            'uom_conversion_factor' => $conversionFactor,
            'base_ordered_qty' => $baseOrderedQty,
            'unit_price' => $unitPrice,
            'gross_amount' => $grossAmount,
            'discount_pct' => $discountPct,
            'discount_amount' => $effectiveDiscount,
            'tax_rate' => $taxRate,
            'tax_inclusive' => $taxInclusive,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * Hitung dan perbarui status pemenuhan PR (PurchaseRequestFulfillmentStatusEnum).
     */
    public function updatePrFulfillmentStatus(int $prId, int $tenantId): void
    {
        /** @var PurchaseRequest|null $pr */
        $pr = PurchaseRequest::query()->where('tenant_id', $tenantId)->find($prId);
        if (! $pr) {
            return;
        }

        $allFullyOrdered = true;
        $hasAnyOrdered = false;

        foreach ($pr->lines as $line) {
            $conv = (string) $line->converted_to_po_qty;
            $app = (string) $line->approved_qty;

            if (bccomp($conv, '0', 4) > 0) {
                $hasAnyOrdered = true;
            }

            if (bccomp($conv, $app, 4) < 0) {
                $allFullyOrdered = false;
            }
        }

        if (! $hasAnyOrdered) {
            $pr->fulfillment_status = PurchaseRequestFulfillmentStatusEnum::Unfulfilled;
        } elseif ($allFullyOrdered) {
            $pr->fulfillment_status = PurchaseRequestFulfillmentStatusEnum::FullyOrdered;
        } else {
            $pr->fulfillment_status = PurchaseRequestFulfillmentStatusEnum::PartiallyOrdered;
        }

        $pr->save();
    }
}
