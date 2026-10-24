<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\InvoiceMatchingStatusEnum;
use App\Enums\InvoiceSettlementStatusEnum;
use App\Enums\PoInvoicingStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Models\SupplierInvoiceGrnMatch;
use App\Modules\Purchasing\Models\SupplierInvoiceLine;
use App\Services\AuditTrailService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceMatchingService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService
    ) {}

    /**
     * Mencatat Faktur Pemasok (Supplier Invoice) baru.
     * Menerapkan normalisasi nomor faktur dan penolakan duplikasi (AT-09).
     *
     * @param  array{
     *     store_id: int,
     *     supplier_id: int,
     *     purchase_order_id?: ?int,
     *     supplier_invoice_number: string,
     *     invoice_date: string,
     *     due_date: string,
     *     currency_code?: string,
     *     exchange_rate?: string|float,
     *     additional_charges_amount?: string|float,
     *     notes?: ?string
     * }  $headerData
     * @param  array<int, array{
     *     purchasable_type: string,
     *     purchasable_id: int,
     *     purchase_order_line_id?: ?int,
     *     invoiced_qty: string|float|int,
     *     unit_price: string|float|int,
     *     discount_amount?: string|float|int,
     *     tax_rate?: string|float|int,
     *     tax_amount?: string|float|int,
     *     notes?: ?string
     * }>  $linesData
     *
     * @throws ValidationException
     */
    public function createSupplierInvoice(
        array $headerData,
        array $linesData,
        User $actor
    ): SupplierInvoice {
        $tenantId = (int) $actor->tenant_id;
        $supplierId = (int) $headerData['supplier_id'];
        $storeId = (int) $headerData['store_id'];
        $rawInvoiceNumber = trim((string) $headerData['supplier_invoice_number']);

        if ($rawInvoiceNumber === '') {
            throw ValidationException::withMessages([
                'supplier_invoice_number' => ['Nomor faktur pemasok wajib diisi.'],
            ]);
        }

        // 1. Normalisasi nomor faktur pemasok (AT-09)
        $normalizedNumber = SupplierInvoice::normalizeInvoiceNumber($rawInvoiceNumber);

        // 2. Deteksi duplikasi nomor faktur untuk supplier_id yang sama dalam tenant yang sama
        $duplicate = SupplierInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->where('normalized_supplier_invoice_number', $normalizedNumber)
            ->where('status', '!=', SupplierInvoiceStatusEnum::Cancelled)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'supplier_invoice_number' => [
                    "Nomor faktur pemasok '{$rawInvoiceNumber}' (normalisasi: {$normalizedNumber}) sudah pernah dicatat untuk pemasok ini.",
                ],
            ]);
        }

        // Pastikan supplier ada dan aktif
        $supplier = Supplier::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($supplierId);

        if (! $supplier->isActive()) {
            throw ValidationException::withMessages([
                'supplier_id' => ["Pemasok '{$supplier->name}' tidak aktif."],
            ]);
        }

        if (empty($linesData)) {
            throw ValidationException::withMessages([
                'lines' => ['Minimal harus ada 1 baris item tagihan pada faktur pemasok.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $storeId, $supplierId, $headerData, $linesData, $rawInvoiceNumber, $normalizedNumber, $actor) {
            $internalNumber = $this->sequenceService->nextNumber(
                tenantId: $tenantId,
                docType: DocumentSequenceService::TYPE_SUPPLIER_INVOICE,
                storeId: $storeId
            );

            $currency = $headerData['currency_code'] ?? 'IDR';
            $exchangeRate = (string) ($headerData['exchange_rate'] ?? '1.000000');
            $additionalCharges = (string) ($headerData['additional_charges_amount'] ?? '0.00');

            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'supplier_id' => $supplierId,
                'purchase_order_id' => $headerData['purchase_order_id'] ?? null,
                'internal_invoice_number' => $internalNumber,
                'supplier_invoice_number' => $rawInvoiceNumber,
                'normalized_supplier_invoice_number' => $normalizedNumber,
                'invoice_date' => $headerData['invoice_date'],
                'due_date' => $headerData['due_date'],
                'currency_code' => $currency,
                'exchange_rate' => $exchangeRate,
                'additional_charges_amount' => $additionalCharges,
                'status' => SupplierInvoiceStatusEnum::Draft,
                'matching_status' => InvoiceMatchingStatusEnum::Unmatched,
                'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
                'notes' => $headerData['notes'] ?? null,
            ]);

            $subtotalSum = '0.00';
            $discountSum = '0.00';
            $taxSum = '0.00';
            $lineNumber = 1;

            foreach ($linesData as $index => $item) {
                $qty = (string) $item['invoiced_qty'];
                $price = (string) $item['unit_price'];
                $lineDisc = (string) ($item['discount_amount'] ?? '0.00');
                $taxRate = (string) ($item['tax_rate'] ?? '0.0000');

                if (bccomp($qty, '0', 4) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.invoiced_qty" => ['Kuantitas tagihan harus lebih besar dari 0.'],
                    ]);
                }

                if (bccomp($price, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.unit_price" => ['Harga satuan tidak boleh negatif.'],
                    ]);
                }

                // Kalkulasi per baris
                $gross = bcmul($qty, $price, 2);
                $netBeforeTax = bcsub($gross, $lineDisc, 2);
                if (bccomp($netBeforeTax, '0.00', 2) < 0) {
                    $netBeforeTax = '0.00';
                }

                $lineTax = (string) ($item['tax_amount'] ?? '0.00');
                if (bccomp($lineTax, '0.00', 2) === 0 && bccomp($taxRate, '0.0000', 4) > 0) {
                    $lineTax = bcmul($netBeforeTax, bcdiv($taxRate, '100', 4), 2);
                }

                $lineTotal = bcadd($netBeforeTax, $lineTax, 2);

                $subtotalSum = bcadd($subtotalSum, $gross, 2);
                $discountSum = bcadd($discountSum, $lineDisc, 2);
                $taxSum = bcadd($taxSum, $lineTax, 2);

                SupplierInvoiceLine::query()->create([
                    'tenant_id' => $tenantId,
                    'supplier_invoice_id' => $invoice->id,
                    'purchase_order_line_id' => $item['purchase_order_line_id'] ?? null,
                    'line_number' => $lineNumber++,
                    'purchasable_type' => $item['purchasable_type'],
                    'purchasable_id' => (int) $item['purchasable_id'],
                    'invoiced_qty' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => $lineDisc,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            // Total Moneter Akhir
            $totalAmount = bcadd(bcsub(bcadd($subtotalSum, $taxSum, 2), $discountSum, 2), $additionalCharges, 2);

            $invoice->update([
                'subtotal_amount' => $subtotalSum,
                'discount_amount' => $discountSum,
                'tax_amount' => $taxSum,
                'total_amount' => $totalAmount,
                'outstanding_amount' => $totalAmount,
            ]);

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier_invoice.created',
                userId: $actor->id,
                subjectType: SupplierInvoice::class,
                subjectId: $invoice->id,
                description: "Faktur pemasok #{$invoice->internal_invoice_number} ({$invoice->supplier_invoice_number}) dicatat.",
                metadata: [
                    'supplier_id' => $supplierId,
                    'total_amount' => $totalAmount,
                    'lines_count' => count($linesData),
                ]
            );

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Menjalankan pencocokan Tiga Arah (3-Way Matching):
     * PO vs GRN vs Invoice Line (AT-08 & AT-10).
     *
     * @param  array<int, array{
     *     supplier_invoice_line_id: int,
     *     goods_received_note_line_id: int,
     *     matched_qty: string|float|int
     * }>  $matchesPlan
     */
    public function performThreeWayMatching(
        SupplierInvoice $invoice,
        array $matchesPlan,
        User $actor
    ): SupplierInvoice {
        if ($invoice->isApprovedPayable() || $invoice->isCancelled()) {
            throw new DomainException("Faktur dengan status {$invoice->status->value} tidak dapat dicocokkan ulang.");
        }

        $tenantId = (int) $invoice->tenant_id;

        return DB::transaction(function () use ($invoice, $matchesPlan, $actor, $tenantId) {
            /** @var SupplierInvoice $lockedInvoice */
            $lockedInvoice = SupplierInvoice::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            // Ambil toleransi harga tenant
            $settingPct = Setting::query()->where('tenant_id', $tenantId)->where('key', 'purchasing.match.price_tolerance_pct')->first();
            $tolerancePct = $settingPct ? (string) $settingPct->value : '0.00';

            $settingAmt = Setting::query()->where('tenant_id', $tenantId)->where('key', 'purchasing.match.price_tolerance_amount')->first();
            $toleranceAmt = $settingAmt ? (string) $settingAmt->value : '0.00';

            // Bersihkan kecocokan lama untuk faktur ini
            $lockedInvoice->grnMatches()->delete();

            $invLines = $lockedInvoice->lines()->get()->keyBy('id');
            $hasAnyVariance = false;

            foreach ($matchesPlan as $plan) {
                $invLineId = (int) $plan['supplier_invoice_line_id'];
                $grnLineId = (int) $plan['goods_received_note_line_id'];
                $matchedQty = (string) $plan['matched_qty'];

                /** @var SupplierInvoiceLine|null $invLine */
                $invLine = $invLines->get($invLineId);
                if (! $invLine) {
                    throw ValidationException::withMessages([
                        'matches' => ["Baris faktur #{$invLineId} tidak valid."],
                    ]);
                }

                /** @var GoodsReceivedNoteLine $grnLine */
                $grnLine = GoodsReceivedNoteLine::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($grnLineId);

                // Ambil harga dari baris PO terkait
                $poLine = $invLine->purchase_order_line_id !== null ? $invLine->purchaseOrderLine : $grnLine->purchaseOrderLine;
                $poUnitPrice = $poLine instanceof PurchaseOrderLine ? (string) $poLine->unit_price : (string) $grnLine->effective_unit_cost;
                $invUnitPrice = (string) $invLine->unit_price;

                // 1. Evaluasi Varians Kuantitas
                // Kuantitas tersedia dari GRN line: accepted_qty dikurangi yang sudah dimatch di faktur lain
                $alreadyInvoicedQty = (string) SupplierInvoiceGrnMatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('goods_received_note_line_id', $grnLine->id)
                    ->where('supplier_invoice_id', '!=', $lockedInvoice->id)
                    ->sum('matched_qty');

                $availableFromGrn = bcsub((string) $grnLine->accepted_qty, $alreadyInvoicedQty, 4);
                if (bccomp($availableFromGrn, '0', 4) < 0) {
                    $availableFromGrn = '0.0000';
                }

                $qtyVariance = '0.0000';
                $hasQtyVariance = false;
                if (bccomp($matchedQty, $availableFromGrn, 4) > 0) {
                    $qtyVariance = bcsub($matchedQty, $availableFromGrn, 4);
                    $hasQtyVariance = true;
                }

                // 2. Evaluasi Varians Harga Satuan
                $priceDiff = bcsub($invUnitPrice, $poUnitPrice, 4);
                $hasPriceVariance = false;
                $priceVarianceAmount = '0.00';

                if (bccomp($priceDiff, '0', 4) > 0) {
                    // Cek ambang toleransi
                    $variancePct = bcmul(bcdiv($priceDiff, $poUnitPrice !== '0.0000' ? $poUnitPrice : '1.0000', 6), '100', 4);
                    $diffAmount = bcmul($priceDiff, $matchedQty, 2);

                    if (bccomp($variancePct, $tolerancePct, 4) > 0 && bccomp($diffAmount, $toleranceAmt, 2) > 0) {
                        $hasPriceVariance = true;
                        $priceVarianceAmount = $diffAmount;
                    }
                }

                // Status Varians
                $varianceStatus = 'none';
                if ($hasQtyVariance && $hasPriceVariance) {
                    $varianceStatus = 'both';
                    $hasAnyVariance = true;
                } elseif ($hasQtyVariance) {
                    $varianceStatus = 'quantity_variance';
                    $hasAnyVariance = true;
                } elseif ($hasPriceVariance) {
                    $varianceStatus = 'price_variance';
                    $hasAnyVariance = true;
                }

                SupplierInvoiceGrnMatch::query()->create([
                    'tenant_id' => $tenantId,
                    'supplier_invoice_id' => $lockedInvoice->id,
                    'supplier_invoice_line_id' => $invLine->id,
                    'goods_received_note_line_id' => $grnLine->id,
                    'matched_qty' => $matchedQty,
                    'po_unit_price' => $poUnitPrice,
                    'invoice_unit_price' => $invUnitPrice,
                    'qty_variance' => $qtyVariance,
                    'price_variance_amount' => $priceVarianceAmount,
                    'tax_variance_amount' => '0.00',
                    'variance_status' => $varianceStatus,
                    'is_variance_approved' => false,
                ]);
            }

            // 3. Tentukan Status Faktur Pasca Matching (AT-10)
            if ($hasAnyVariance) {
                $lockedInvoice->status = SupplierInvoiceStatusEnum::VarianceHold;
                $lockedInvoice->matching_status = InvoiceMatchingStatusEnum::VarianceDetected;
                $lockedInvoice->hold_reason = 'Terdeteksi selisih kuantitas atau harga melebihi batas toleransi pada 3-Way Match.';
            } else {
                $lockedInvoice->status = SupplierInvoiceStatusEnum::Submitted;
                $lockedInvoice->matching_status = InvoiceMatchingStatusEnum::Matched;
                $lockedInvoice->hold_reason = null;
            }
            $lockedInvoice->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier_invoice.matched',
                userId: $actor->id,
                subjectType: SupplierInvoice::class,
                subjectId: $lockedInvoice->id,
                description: "Rekonsiliasi 3-Way Match selesai untuk faktur #{$lockedInvoice->internal_invoice_number} (Status: {$lockedInvoice->status->value}).",
                metadata: [
                    'matching_status' => $lockedInvoice->matching_status->value,
                    'has_variance' => $hasAnyVariance,
                ]
            );

            return $lockedInvoice->fresh(['lines.grnMatches', 'grnMatches']);
        });
    }

    /**
     * Menyetujui Varians Selisih 3-Way Match (AT-10).
     * Membebaskan faktur dari status variance_hold menjadi submitted (siap diapprove ke payable).
     */
    public function approveVariance(
        SupplierInvoice $invoice,
        User $actor,
        string $approvalNotes
    ): SupplierInvoice {
        if (! $invoice->isVarianceHold()) {
            throw new DomainException("Faktur #{$invoice->internal_invoice_number} tidak berada dalam status Ditahan (Variance Hold).");
        }

        if (trim($approvalNotes) === '') {
            throw ValidationException::withMessages([
                'notes' => ['Catatan justifikasi persetujuan selisih varians wajib diisi.'],
            ]);
        }

        $tenantId = (int) $invoice->tenant_id;

        return DB::transaction(function () use ($invoice, $actor, $approvalNotes, $tenantId) {
            /** @var SupplierInvoice $lockedInvoice */
            $lockedInvoice = SupplierInvoice::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            // Tandai seluruh match varians sebagai disetujui
            $lockedInvoice->grnMatches()->update([
                'is_variance_approved' => true,
                'variance_approved_by_user_id' => $actor->id,
                'variance_approval_notes' => $approvalNotes,
            ]);

            // Lepaskan dari Variance Hold
            $lockedInvoice->status = SupplierInvoiceStatusEnum::Submitted;
            $lockedInvoice->matching_status = InvoiceMatchingStatusEnum::Matched;
            $lockedInvoice->hold_approved_by_user_id = $actor->id;
            $lockedInvoice->hold_approved_at = now();
            $lockedInvoice->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier_invoice.variance_approved',
                userId: $actor->id,
                subjectType: SupplierInvoice::class,
                subjectId: $lockedInvoice->id,
                description: "Selisih varians pada faktur #{$lockedInvoice->internal_invoice_number} disetujui oleh {$actor->name}.",
                metadata: ['approval_notes' => $approvalNotes]
            );

            return $lockedInvoice->fresh(['lines', 'grnMatches']);
        });
    }

    /**
     * Menyetujui Faktur Menjadi Kewajiban Siap-Bayar (Approved Payable) (AT-08 & AT-17):
     * 1. Validasi status: tidak boleh draf atau variance_hold yang belum disetujui.
     * 2. Perbarui akumulator invoiced_qty pada purchase_order_lines.
     * 3. Evaluasi dan perbarui invoicing_status pada purchase_orders.
     * 4. Terbitkan rekaman kontrak outbox idempoten ke accounting_payable_outbox menuju M7.
     */
    public function approvePayable(SupplierInvoice $invoice, User $actor): SupplierInvoice
    {
        if ($invoice->isApprovedPayable()) {
            throw new DomainException("Faktur #{$invoice->internal_invoice_number} sudah berstatus Disetujui Siap-Bayar (Approved Payable).");
        }

        if ($invoice->isVarianceHold()) {
            throw new DomainException('Faktur tertahan selisih (Variance Hold) tidak dapat disetujui menjadi kewajiban siap bayar sebelum variansnya disetujui.');
        }

        if ($invoice->isCancelled()) {
            throw new DomainException('Faktur yang telah dibatalkan tidak dapat disetujui.');
        }

        $tenantId = (int) $invoice->tenant_id;

        return DB::transaction(function () use ($invoice, $actor, $tenantId) {
            /** @var SupplierInvoice $lockedInvoice */
            $lockedInvoice = SupplierInvoice::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            // Kunci baris PO terurut
            $poId = $lockedInvoice->purchase_order_id;
            /** @var PurchaseOrder|null $po */
            $po = $poId ? PurchaseOrder::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($poId) : null;

            $invLines = $lockedInvoice->lines()->get();

            // 1. Perbarui akumulator invoiced_qty pada PO Lines
            foreach ($invLines as $line) {
                if ($line->purchase_order_line_id) {
                    /** @var PurchaseOrderLine|null $poLine */
                    $poLine = PurchaseOrderLine::query()
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->find($line->purchase_order_line_id);

                    if ($poLine) {
                        $poLine->invoiced_qty = bcadd((string) $poLine->invoiced_qty, (string) $line->invoiced_qty, 4);
                        $poLine->save();
                    }
                }
            }

            // 2. Evaluasi invoicing_status pada PO (AT-08)
            if ($po) {
                $allPolines = $po->lines()->get();
                $allFullyInvoiced = true;
                $hasAnyInvoiced = false;

                foreach ($allPolines as $pl) {
                    // Bandingkan dengan accepted_qty (atau ordered_qty jika belum ada penerimaan)
                    $targetQty = bccomp((string) $pl->accepted_qty, '0', 4) > 0 ? (string) $pl->accepted_qty : (string) $pl->ordered_qty;

                    if (bccomp((string) $pl->invoiced_qty, $targetQty, 4) < 0) {
                        $allFullyInvoiced = false;
                    }
                    if (bccomp((string) $pl->invoiced_qty, '0', 4) > 0) {
                        $hasAnyInvoiced = true;
                    }
                }

                if ($allFullyInvoiced && $hasAnyInvoiced) {
                    $po->invoicing_status = PoInvoicingStatusEnum::FullyInvoiced;
                } elseif ($hasAnyInvoiced) {
                    $po->invoicing_status = PoInvoicingStatusEnum::PartiallyInvoiced;
                } else {
                    $po->invoicing_status = PoInvoicingStatusEnum::NotInvoiced;
                }
                $po->save();
            }

            // 3. Ubah status Faktur menjadi Approved Payable
            $lockedInvoice->status = SupplierInvoiceStatusEnum::ApprovedPayable;
            $lockedInvoice->approved_payable_at = now();
            $lockedInvoice->approved_payable_by_user_id = $actor->id;
            $lockedInvoice->save();

            // 4. Terbitkan Kontrak Integrasi Outbox M5 -> M7 (AT-17)
            $eventId = Str::uuid()->toString();
            $payload = [
                'schema_version' => '1.0',
                'event_id' => $eventId,
                'tenant_id' => $tenantId,
                'store_id' => $lockedInvoice->store_id,
                'supplier_id' => $lockedInvoice->supplier_id,
                'source_document_type' => 'supplier_invoice',
                'source_document_id' => $lockedInvoice->id,
                'source_document_number' => $lockedInvoice->internal_invoice_number,
                'supplier_invoice_number' => $lockedInvoice->supplier_invoice_number,
                'accounting_date' => $lockedInvoice->invoice_date->toDateString(),
                'due_date' => $lockedInvoice->due_date->toDateString(),
                'currency_code' => $lockedInvoice->currency_code,
                'exchange_rate' => (string) $lockedInvoice->exchange_rate,
                'subtotal_amount' => (string) $lockedInvoice->subtotal_amount,
                'discount_amount' => (string) $lockedInvoice->discount_amount,
                'tax_amount' => (string) $lockedInvoice->tax_amount,
                'additional_charges_amount' => (string) $lockedInvoice->additional_charges_amount,
                'total_amount' => (string) $lockedInvoice->total_amount,
                'line_items' => $invLines->map(fn (SupplierInvoiceLine $l) => [
                    'purchasable_type' => $l->purchasable_type,
                    'purchasable_id' => $l->purchasable_id,
                    'invoiced_qty' => (string) $l->invoiced_qty,
                    'unit_price' => (string) $l->unit_price,
                    'tax_amount' => (string) $l->tax_amount,
                    'line_total' => (string) $l->line_total,
                    'purchase_order_line_id' => $l->purchase_order_line_id,
                ])->toArray(),
            ];

            AccountingPayableOutbox::query()->create([
                'event_id' => $eventId,
                'tenant_id' => $tenantId,
                'store_id' => $lockedInvoice->store_id,
                'event_type' => 'purchasing.invoice.approved_payable',
                'idempotency_key' => "invoice:payable:{$tenantId}:{$lockedInvoice->id}",
                'source_document_type' => 'supplier_invoice',
                'source_document_id' => $lockedInvoice->id,
                'source_document_number' => $lockedInvoice->internal_invoice_number,
                'status' => 'pending',
                'payload' => $payload,
            ]);

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier_invoice.approved_payable',
                userId: $actor->id,
                subjectType: SupplierInvoice::class,
                subjectId: $lockedInvoice->id,
                description: "Faktur pemasok #{$lockedInvoice->internal_invoice_number} disetujui menjadi kewajiban siap bayar (Approved Payable). Kontrak AP outbox telah diterbitkan ke M7.",
                metadata: [
                    'total_amount' => $lockedInvoice->total_amount,
                    'po_id' => $po?->id,
                    'po_invoicing_status' => $po?->invoicing_status->value,
                ]
            );

            return $lockedInvoice->fresh(['lines', 'grnMatches', 'purchaseOrder']);
        }, attempts: 3);
    }

    /**
     * Membatalkan faktur pemasok.
     */
    public function cancelSupplierInvoice(SupplierInvoice $invoice, User $actor, string $reason): SupplierInvoice
    {
        if ($invoice->isApprovedPayable()) {
            throw new DomainException('Faktur yang telah disetujui menjadi kewajiban siap bayar tidak dapat dibatalkan secara langsung.');
        }

        $invoice->status = SupplierInvoiceStatusEnum::Cancelled;
        $invoice->notes = ($invoice->notes ? $invoice->notes."\n" : '')."Dibatalkan oleh {$actor->name}: {$reason}";
        $invoice->save();

        $this->auditTrailService->log(
            tenantId: (int) $invoice->tenant_id,
            action: 'supplier_invoice.cancelled',
            userId: $actor->id,
            subjectType: SupplierInvoice::class,
            subjectId: $invoice->id,
            description: "Faktur #{$invoice->internal_invoice_number} dibatalkan.",
            metadata: ['reason' => $reason]
        );

        return $invoice;
    }
}
