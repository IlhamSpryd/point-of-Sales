<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Products\DataTransferObjects\PriceResolutionContextDto;
use App\Modules\Products\Services\CatalogPriceResolverService;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Modules\Sales\Enums\QuotationStatusEnum;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        protected CatalogPriceResolverService $priceResolver,
        protected SalesOrderService $salesOrderService,
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext,
        protected CustomerCreditService $customerCreditService
    ) {}

    /**
     * Membuat draft penawaran harga (Quotation).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(array $payload, ?User $actor = null): Quotation
    {
        $tenantId = (int) ($actor && $actor->tenant_id ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($payload, $actor, $tenantId) {
            $customerId = (int) ($payload['customer_id'] ?? 0);
            if ($customerId <= 0) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Pelanggan wajib dipilih untuk membuat penawaran harga (Quotation).'],
                ]);
            }

            $customer = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $customerId)
                ->first();

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Pelanggan tidak valid pada tenant ini.'],
                ]);
            }

            $storeId = (int) ($payload['store_id'] ?? 0);
            if ($storeId <= 0) {
                $storeId = (int) ($customer->default_store_id ?: $this->tenantContext->getStoreId());
            }

            if ($storeId <= 0) {
                $firstStore = Store::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->first();
                $storeId = $firstStore ? (int) $firstStore->id : 1;
            }

            $items = $payload['items'] ?? $payload['lines'] ?? [];
            if (! is_array($items) || count($items) === 0) {
                throw ValidationException::withMessages([
                    'items' => ['Quotation wajib memiliki minimal 1 item penawaran.'],
                ]);
            }

            $issueDate = ! empty($payload['issue_date'])
                ? Carbon::parse($payload['issue_date'])->toDateString()
                : Carbon::today()->toDateString();

            $validUntil = ! empty($payload['valid_until'])
                ? Carbon::parse($payload['valid_until'])->toDateString()
                : Carbon::parse($issueDate)->addDays(30)->toDateString();

            $quotationNumber = ! empty($payload['quotation_number'])
                ? trim((string) $payload['quotation_number'])
                : $this->sequenceService->nextNumber($tenantId, 'QTN', $storeId);

            $customerAddressId = ! empty($payload['customer_address_id']) ? (int) $payload['customer_address_id'] : null;
            $customerAddress = $customerAddressId ? CustomerAddress::withoutGlobalScopes()->find($customerAddressId) : null;

            $customerSnapshot = [
                'customer_id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'customer_name' => $customer->name,
                'customer_type' => $customer->customer_type,
                'company_name' => $customer->company_name,
                'tax_id' => $customer->tax_id,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customerAddress?->toArray(),
            ];

            $paymentTermsDays = isset($payload['payment_terms_days'])
                ? (int) $payload['payment_terms_days']
                : 0;

            $salesRepUserId = ! empty($payload['sales_rep_user_id']) ? (int) $payload['sales_rep_user_id'] : null;
            $createdByUserId = $actor ? (int) $actor->id : (int) ($payload['created_by_user_id'] ?? 1);

            $quotation = new Quotation;
            $quotation->tenant_id = $tenantId;
            $quotation->store_id = $storeId;
            $quotation->quotation_number = $quotationNumber;
            $quotation->version_number = (int) ($payload['version_number'] ?? 1);
            $quotation->parent_quotation_id = ! empty($payload['parent_quotation_id']) ? (int) $payload['parent_quotation_id'] : null;
            $quotation->customer_id = $customer->id;
            $quotation->customer_address_id = $customerAddressId;
            $quotation->sales_rep_user_id = $salesRepUserId;
            $quotation->status = QuotationStatusEnum::DRAFT;
            $quotation->issue_date = $issueDate;
            $quotation->valid_until = $validUntil;
            $quotation->currency_code = (string) ($payload['currency_code'] ?? 'IDR');
            $quotation->payment_terms_days = $paymentTermsDays;
            $quotation->subtotal = '0.00';
            $quotation->discount_amount = '0.00';
            $quotation->tax_amount = '0.00';
            $quotation->total_amount = '0.00';
            $quotation->customer_snapshot_json = $customerSnapshot;
            $quotation->notes = $payload['notes'] ?? null;
            $quotation->terms_and_conditions = $payload['terms_and_conditions'] ?? null;
            $quotation->requires_approval = false;
            $quotation->idempotency_key = $payload['idempotency_key'] ?? null;
            $quotation->created_by_user_id = $createdByUserId;
            $quotation->save();

            $subtotalSum = '0.00';
            $discountSum = '0.00';
            $taxSum = '0.00';
            $totalSum = '0.00';
            $requiresApproval = false;

            $lineNumber = 1;
            foreach ($items as $itemData) {
                $productId = (int) ($itemData['product_id'] ?? 0);
                $product = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $productId)
                    ->first();

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Produk ID {$productId} tidak ditemukan."],
                    ]);
                }

                $variantId = isset($itemData['variant_id']) ? (int) $itemData['variant_id'] : null;
                $uomId = isset($itemData['uom_id']) ? (int) $itemData['uom_id'] : null;
                $qty = bcadd((string) ($itemData['quantity'] ?? '1'), '0', 4);

                $unitPrice = null;
                $basePrice = null;
                $priceListId = null;
                $pricingSource = 'MANUAL';

                if (isset($itemData['unit_price'])) {
                    $unitPrice = bcadd((string) $itemData['unit_price'], '0', 2);
                    $basePrice = isset($itemData['base_unit_price'])
                        ? bcadd((string) $itemData['base_unit_price'], '0', 2)
                        : $unitPrice;
                    $priceListId = isset($itemData['price_list_id']) ? (int) $itemData['price_list_id'] : null;
                    $pricingSource = (string) ($itemData['pricing_source'] ?? 'MANUAL');
                } else {
                    $context = new PriceResolutionContextDto(
                        tenantId: $tenantId,
                        productId: $productId,
                        storeId: $storeId,
                        variantId: $variantId,
                        uomId: $uomId,
                        quantity: (float) $qty,
                        customerId: $customer->id,
                        customerGroupId: $customer->customer_group_id ? (int) $customer->customer_group_id : null,
                        transactionDate: Carbon::parse($issueDate)
                    );

                    $resolved = $this->priceResolver->resolveCustomerPrice($context);
                    $unitPrice = bcadd((string) $resolved->unitPrice, '0', 2);
                    $basePrice = bcadd((string) $resolved->baseUnitPrice, '0', 2);
                    $priceListId = $resolved->priceListId;
                    $pricingSource = $resolved->pricingSource;
                }

                $lineSubtotal = bcmul($qty, $unitPrice, 2);
                $lineDiscount = isset($itemData['discount_amount'])
                    ? bcadd((string) $itemData['discount_amount'], '0', 2)
                    : '0.00';

                $taxRate = isset($itemData['tax_rate'])
                    ? bcadd((string) $itemData['tax_rate'], '0', 2)
                    : '0.00';

                $netLine = bcsub($lineSubtotal, $lineDiscount, 2);
                $lineTax = isset($itemData['tax_amount'])
                    ? bcadd((string) $itemData['tax_amount'], '0', 2)
                    : bcdiv(bcmul($netLine, $taxRate, 4), '100', 2);

                $lineTotal = bcadd($netLine, $lineTax, 2);

                // Margin / discount threshold check: > 15% discount requires approval
                if (bccomp($lineSubtotal, '0.00', 2) > 0) {
                    $discountPct = bcdiv(bcmul($lineDiscount, '100', 4), $lineSubtotal, 2);
                    if (bccomp($discountPct, '15.00', 2) > 0) {
                        $requiresApproval = true;
                    }
                }

                $line = new QuotationLine;
                $line->tenant_id = $tenantId;
                $line->quotation_id = $quotation->id;
                $line->line_number = $lineNumber++;
                $line->product_id = $productId;
                $line->variant_id = $variantId;
                $line->uom_id = $uomId;
                $line->description = $itemData['description'] ?? $product->product_name ?? $product->name ?? 'Item';
                $line->quantity = $qty;
                $line->base_unit_price = $basePrice;
                $line->unit_price = $unitPrice;
                $line->price_list_id = $priceListId;
                $line->pricing_source = $pricingSource;
                $line->discount_amount = $lineDiscount;
                $line->tax_class_id = isset($itemData['tax_class_id']) ? (int) $itemData['tax_class_id'] : null;
                $line->tax_rate = $taxRate;
                $line->tax_amount = $lineTax;
                $line->line_total = $lineTotal;
                $line->save();

                $subtotalSum = bcadd($subtotalSum, $lineSubtotal, 2);
                $discountSum = bcadd($discountSum, $lineDiscount, 2);
                $taxSum = bcadd($taxSum, $lineTax, 2);
                $totalSum = bcadd($totalSum, $lineTotal, 2);
            }

            if (! empty($payload['requires_approval'])) {
                $requiresApproval = true;
            }

            $quotation->subtotal = $subtotalSum;
            $quotation->discount_amount = $discountSum;
            $quotation->tax_amount = $taxSum;
            $quotation->total_amount = $totalSum;
            $quotation->requires_approval = $requiresApproval;
            if ($requiresApproval) {
                $quotation->status = QuotationStatusEnum::PENDING_APPROVAL;
            }
            $quotation->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'quotation.create_draft',
                userId: $actor?->id,
                storeId: $storeId,
                subjectType: Quotation::class,
                subjectId: $quotation->id,
                description: "Membuat draft penawaran {$quotation->quotation_number} (v{$quotation->version_number}) untuk {$customer->name}",
                newValues: $quotation->toArray()
            );

            return $quotation->fresh(['lines', 'customer']);
        });
    }

    /**
     * Mengajukan Quotation untuk approval atau langsung mengirim ke pelanggan jika tidak butuh approval.
     */
    public function submitForApprovalOrSend(Quotation $quotation, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            $locked = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $quotation->tenant_id)
                ->where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canBeEdited()) {
                throw new DomainException("Quotation dalam status {$locked->status->value} tidak dapat diajukan/diubah.");
            }

            // Soft warning: Jika quotation bertempo dan eksposur kredit melanggar, alihkan ke PENDING_APPROVAL
            if ($locked->payment_terms_days > 0) {
                $eval = $this->customerCreditService->evaluateCreditExposure(
                    (int) $locked->tenant_id,
                    (int) $locked->customer_id,
                    (string) $locked->total_amount
                );
                if ($eval->status->isBlocked()) {
                    $locked->requires_approval = true;
                }
            }

            if ($locked->requires_approval && $locked->approved_at === null) {
                $locked->status = QuotationStatusEnum::PENDING_APPROVAL;
            } else {
                $locked->status = QuotationStatusEnum::SENT;
            }

            $locked->save();

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'quotation.submit_or_send',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: Quotation::class,
                subjectId: $locked->id,
                description: "Quotation {$locked->quotation_number} beralih ke status {$locked->status->value}",
                newValues: ['status' => $locked->status->value]
            );

            return $locked;
        });
    }

    /**
     * Menyetujui (Approve) Quotation yang berstatus PENDING_APPROVAL.
     */
    public function approve(Quotation $quotation, User $approver, ?string $notes = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $approver, $notes) {
            $locked = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $quotation->tenant_id)
                ->where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [QuotationStatusEnum::PENDING_APPROVAL, QuotationStatusEnum::DRAFT], true)) {
                throw new DomainException("Quotation dalam status {$locked->status->value} tidak memerlukan persetujuan.");
            }

            $locked->approved_by_user_id = $approver->id;
            $locked->approved_at = Carbon::now();
            $locked->status = QuotationStatusEnum::APPROVED;
            if ($notes !== null && $notes !== '') {
                $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '')."[Approval Notes: {$notes}]");
            }
            $locked->save();

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'quotation.approve',
                userId: $approver->id,
                storeId: $locked->store_id,
                subjectType: Quotation::class,
                subjectId: $locked->id,
                description: "Quotation {$locked->quotation_number} disetujui oleh {$approver->name}",
                newValues: ['status' => $locked->status->value, 'approved_by' => $approver->id]
            );

            return $locked;
        });
    }

    /**
     * Menolak (Reject) Quotation.
     */
    public function reject(Quotation $quotation, User $actor, string $reason): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor, $reason) {
            $locked = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $quotation->tenant_id)
                ->where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw new DomainException("Quotation dalam status {$locked->status->value} tidak dapat ditolak.");
            }

            $locked->status = QuotationStatusEnum::REJECTED;
            $locked->rejected_reason = $reason;
            $locked->save();

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'quotation.reject',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: Quotation::class,
                subjectId: $locked->id,
                description: "Quotation {$locked->quotation_number} ditolak. Alasan: {$reason}",
                newValues: ['status' => $locked->status->value, 'rejected_reason' => $reason]
            );

            return $locked;
        });
    }

    /**
     * Menandai penawaran harga diterima oleh pelanggan (ACCEPTED).
     */
    public function accept(Quotation $quotation, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            $locked = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $quotation->tenant_id)
                ->where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->valid_until->lt(Carbon::today())) {
                $locked->status = QuotationStatusEnum::EXPIRED;
                $locked->save();
                throw new DomainException("Quotation telah kedaluwarsa sejak {$locked->valid_until->toDateString()}.");
            }

            if (! in_array($locked->status, [QuotationStatusEnum::APPROVED, QuotationStatusEnum::SENT, QuotationStatusEnum::DRAFT], true)) {
                throw new DomainException("Quotation dalam status {$locked->status->value} tidak dapat diterima.");
            }

            $locked->status = QuotationStatusEnum::ACCEPTED;
            $locked->save();

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'quotation.accept',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: Quotation::class,
                subjectId: $locked->id,
                description: "Quotation {$locked->quotation_number} diterima oleh pelanggan.",
                newValues: ['status' => $locked->status->value]
            );

            return $locked;
        });
    }

    /**
     * Membuat revisi baru dari Quotation (version_number + 1, versi lama menjadi SUPERSEDED).
     *
     * @param  array<string, mixed>  $changes
     */
    public function createRevision(Quotation $quotation, array $changes, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $changes, $actor) {
            $locked = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $quotation->tenant_id)
                ->where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === QuotationStatusEnum::CONVERTED) {
                throw new DomainException('Quotation yang telah dikonversi ke Sales Order tidak dapat direvisi.');
            }

            $locked->status = QuotationStatusEnum::SUPERSEDED;
            $locked->save();

            $newPayload = array_merge([
                'customer_id' => $locked->customer_id,
                'store_id' => $locked->store_id,
                'quotation_number' => $locked->quotation_number,
                'version_number' => $locked->version_number + 1,
                'parent_quotation_id' => $locked->id,
                'customer_address_id' => $locked->customer_address_id,
                'sales_rep_user_id' => $locked->sales_rep_user_id,
                'currency_code' => $locked->currency_code,
                'payment_terms_days' => $locked->payment_terms_days,
                'notes' => $locked->notes,
                'terms_and_conditions' => $locked->terms_and_conditions,
                'items' => $locked->lines->map(fn (QuotationLine $l) => [
                    'product_id' => $l->product_id,
                    'variant_id' => $l->variant_id,
                    'uom_id' => $l->uom_id,
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                    'base_unit_price' => $l->base_unit_price,
                    'price_list_id' => $l->price_list_id,
                    'pricing_source' => $l->pricing_source,
                    'discount_amount' => $l->discount_amount,
                    'tax_rate' => $l->tax_rate,
                    'tax_amount' => $l->tax_amount,
                ])->toArray(),
            ], $changes);

            $revision = $this->createDraft($newPayload, $actor);

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'quotation.revision_created',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: Quotation::class,
                subjectId: $revision->id,
                description: "Revisi Quotation dibuat: {$revision->quotation_number} (v{$revision->version_number}) menggantikan v{$locked->version_number}",
                newValues: $revision->toArray()
            );

            return $revision;
        });
    }

    /**
     * Konversi SATU-KALI dari Quotation ke Sales Order.
     * Menggunakan row lock (lockForUpdate) untuk mencegah race-condition dan duplikasi.
     */
    public function convertToSalesOrder(Quotation $quotation, User $actor, ?string $idempotencyKey = null): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $actor, $idempotencyKey) {
            // 1. Kunci baris Quotation (A0/A8 Lock Graph Rule)
            $locked = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $quotation->tenant_id)
                ->where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Anti-duplikasi: Tolak jika sudah pernah dikonversi
            if ($locked->status === QuotationStatusEnum::CONVERTED || $locked->converted_sales_order_id !== null) {
                throw new DomainException("Quotation {$locked->quotation_number} sudah pernah dikonversi ke Sales Order.");
            }

            // 3. Status harus ACCEPTED
            if ($locked->status !== QuotationStatusEnum::ACCEPTED) {
                throw new DomainException("Hanya Quotation berstatus ACCEPTED yang dapat dikonversi ke Sales Order. Status saat ini: {$locked->status->value}");
            }

            // 4. Verifikasi masa berlaku (valid_until >= today)
            if ($locked->valid_until->lt(Carbon::today())) {
                $locked->status = QuotationStatusEnum::EXPIRED;
                $locked->save();
                throw new DomainException("Gagal konversi: Quotation telah kedaluwarsa pada {$locked->valid_until->toDateString()}.");
            }

            // 5. Buat Sales Order melalui SalesOrderService
            $idempKey = $idempotencyKey ?: "qtn_convert_{$locked->id}_{$locked->version_number}";
            $salesOrder = $this->salesOrderService->createOrderFromQuotation($locked, $actor, $idempKey);

            // 6. Tandai Quotation sebagai CONVERTED secara permanen
            $locked->converted_sales_order_id = $salesOrder->id;
            $locked->converted_at = Carbon::now();
            $locked->status = QuotationStatusEnum::CONVERTED;
            $locked->save();

            // 7. Audit Log
            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'quotation.converted_to_so',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: Quotation::class,
                subjectId: $locked->id,
                description: "Quotation {$locked->quotation_number} (v{$locked->version_number}) berhasil dikonversi menjadi Sales Order {$salesOrder->so_number}",
                newValues: [
                    'converted_sales_order_id' => $salesOrder->id,
                    'converted_at' => $locked->converted_at->toIso8601String(),
                    'status' => $locked->status->value,
                ]
            );

            return $salesOrder;
        });
    }
}
