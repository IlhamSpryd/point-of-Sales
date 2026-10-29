<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Products\DataTransferObjects\PriceResolutionContextDto;
use App\Modules\Products\Services\CatalogPriceResolverService;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Modules\Sales\Enums\SalesOrderFulfillmentStatusEnum;
use App\Modules\Sales\Enums\SalesOrderInvoicingStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        protected CatalogPriceResolverService $priceResolver,
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext,
        protected InventoryLedgerService $inventoryLedgerService,
        protected CustomerCreditService $customerCreditService
    ) {}

    /**
     * Membuat Sales Order baru secara manual (B2B / Direct).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createOrder(array $payload, User $actor, ?string $idempotencyKey = null): SalesOrder
    {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($payload, $actor, $tenantId, $idempotencyKey) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = SalesOrder::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $customerId = (int) ($payload['customer_id'] ?? 0);
            if ($customerId <= 0) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Pelanggan wajib dipilih untuk Sales Order.'],
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
                    'items' => ['Sales Order wajib memiliki minimal 1 item baris.'],
                ]);
            }

            $orderDate = ! empty($payload['order_date'])
                ? Carbon::parse($payload['order_date'])->toDateString()
                : Carbon::today()->toDateString();

            $expectedDeliveryDate = ! empty($payload['expected_delivery_date'])
                ? Carbon::parse($payload['expected_delivery_date'])->toDateString()
                : null;

            $soNumber = ! empty($payload['so_number'])
                ? trim((string) $payload['so_number'])
                : $this->sequenceService->nextNumber($tenantId, 'SO', $storeId);

            $billingAddressId = ! empty($payload['billing_address_id']) ? (int) $payload['billing_address_id'] : null;
            $shippingAddressId = ! empty($payload['shipping_address_id']) ? (int) $payload['shipping_address_id'] : null;

            $billingAddress = $billingAddressId ? CustomerAddress::withoutGlobalScopes()->find($billingAddressId) : null;
            $shippingAddress = $shippingAddressId ? CustomerAddress::withoutGlobalScopes()->find($shippingAddressId) : null;

            $customerSnapshot = [
                'customer_id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'customer_name' => $customer->name,
                'customer_type' => $customer->customer_type,
                'company_name' => $customer->company_name,
                'tax_id' => $customer->tax_id,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'billing_address' => $billingAddress?->toArray(),
                'shipping_address' => $shippingAddress?->toArray(),
            ];

            $orderSource = (string) ($payload['order_source'] ?? 'DIRECT_B2B');
            $paymentTermsDays = isset($payload['payment_terms_days'])
                ? (int) $payload['payment_terms_days']
                : 0;

            $shippingAmount = isset($payload['shipping_amount'])
                ? bcadd((string) $payload['shipping_amount'], '0', 2)
                : '0.00';

            $salesOrder = new SalesOrder;
            $salesOrder->tenant_id = $tenantId;
            $salesOrder->store_id = $storeId;
            $salesOrder->so_number = $soNumber;
            $salesOrder->customer_id = $customer->id;
            $salesOrder->quotation_id = isset($payload['quotation_id']) ? (int) $payload['quotation_id'] : null;
            $salesOrder->customer_po_number = ! empty($payload['customer_po_number']) ? trim((string) $payload['customer_po_number']) : null;
            $salesOrder->billing_address_id = $billingAddressId;
            $salesOrder->shipping_address_id = $shippingAddressId;
            $salesOrder->order_source = $orderSource;
            $salesOrder->channel_code = ! empty($payload['channel_code']) ? (string) $payload['channel_code'] : null;
            $salesOrder->external_order_id = ! empty($payload['external_order_id']) ? (string) $payload['external_order_id'] : null;
            $salesOrder->status = SalesOrderStatusEnum::DRAFT;
            $salesOrder->fulfillment_status = SalesOrderFulfillmentStatusEnum::UNFULFILLED;
            $salesOrder->invoicing_status = SalesOrderInvoicingStatusEnum::UNINVOICED;
            $salesOrder->order_date = $orderDate;
            $salesOrder->expected_delivery_date = $expectedDeliveryDate;
            $salesOrder->payment_terms_days = $paymentTermsDays;
            $salesOrder->subtotal = '0.00';
            $salesOrder->discount_amount = '0.00';
            $salesOrder->tax_amount = '0.00';
            $salesOrder->shipping_amount = $shippingAmount;
            $salesOrder->total_amount = '0.00';
            $salesOrder->reserved_credit_amount = '0.00';
            $salesOrder->customer_snapshot_json = $customerSnapshot;
            $salesOrder->idempotency_key = $idempotencyKey;
            $salesOrder->created_by_user_id = $actor->id;
            $salesOrder->notes = $payload['notes'] ?? null;
            $salesOrder->save();

            $subtotalSum = '0.00';
            $discountSum = '0.00';
            $taxSum = '0.00';
            $totalSum = '0.00';

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
                $qty = bcadd((string) ($itemData['quantity'] ?? $itemData['ordered_qty'] ?? '1'), '0', 4);

                // Validasi B2B Wholesale MOQ (Minimum Order Quantity)
                $moq = isset($itemData['moq']) ? (string) $itemData['moq'] : (isset($itemData['min_order_qty']) ? (string) $itemData['min_order_qty'] : null);
                if ($moq !== null && bccomp($qty, $moq, 4) < 0) {
                    throw ValidationException::withMessages([
                        'items' => ["Kuantitas produk '{$product->product_name}' ({$qty}) tidak memenuhi batas Minimum Order Quantity (MOQ: {$moq})."],
                    ]);
                }

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
                        transactionDate: Carbon::parse($orderDate)
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

                $line = new SalesOrderLine;
                $line->tenant_id = $tenantId;
                $line->sales_order_id = $salesOrder->id;
                $line->line_number = $lineNumber++;
                $line->product_id = $productId;
                $line->variant_id = $variantId;
                $line->uom_id = $uomId;
                $line->description = $itemData['description'] ?? $product->product_name ?? $product->name ?? 'Item';
                $line->ordered_qty = $qty;
                $line->reserved_qty = '0.0000';
                $line->fulfilled_qty = '0.0000';
                $line->cancelled_qty = '0.0000';
                $line->invoiced_qty = '0.0000';
                $line->returned_qty = '0.0000';
                $line->base_unit_price = $basePrice;
                $line->unit_price = $unitPrice;
                $line->price_list_id = $priceListId;
                $line->pricing_source = $pricingSource;
                $line->discount_amount = $lineDiscount;
                $line->tax_rate = $taxRate;
                $line->tax_amount = $lineTax;
                $line->line_total = $lineTotal;
                $line->save();

                $subtotalSum = bcadd($subtotalSum, $lineSubtotal, 2);
                $discountSum = bcadd($discountSum, $lineDiscount, 2);
                $taxSum = bcadd($taxSum, $lineTax, 2);
                $totalSum = bcadd($totalSum, $lineTotal, 2);
            }

            $finalTotal = bcadd($totalSum, $shippingAmount, 2);

            $salesOrder->subtotal = $subtotalSum;
            $salesOrder->discount_amount = $discountSum;
            $salesOrder->tax_amount = $taxSum;
            $salesOrder->total_amount = $finalTotal;
            $salesOrder->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'sales_order.create',
                userId: $actor->id,
                storeId: $storeId,
                subjectType: SalesOrder::class,
                subjectId: $salesOrder->id,
                description: "Membuat Sales Order baru: {$salesOrder->so_number} untuk {$customer->name}",
                newValues: $salesOrder->toArray()
            );

            return $salesOrder->fresh(['lines', 'customer']);
        });
    }

    /**
     * Membuat Sales Order turunan dari Quotation yang berstatus ACCEPTED.
     */
    public function createOrderFromQuotation(Quotation $quotation, User $actor, ?string $idempotencyKey = null): SalesOrder
    {
        $tenantId = (int) $quotation->tenant_id;

        return DB::transaction(function () use ($quotation, $actor, $tenantId, $idempotencyKey) {
            $key = $idempotencyKey ?: "qtn_convert_{$quotation->id}_{$quotation->version_number}";

            $existing = SalesOrder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $soNumber = $this->sequenceService->nextNumber($tenantId, 'SO', (int) $quotation->store_id);

            $salesOrder = new SalesOrder;
            $salesOrder->tenant_id = $tenantId;
            $salesOrder->store_id = $quotation->store_id;
            $salesOrder->so_number = $soNumber;
            $salesOrder->customer_id = $quotation->customer_id;
            $salesOrder->quotation_id = $quotation->id;
            $salesOrder->billing_address_id = $quotation->customer_address_id;
            $salesOrder->shipping_address_id = $quotation->customer_address_id;
            $salesOrder->order_source = 'QUOTATION';
            $salesOrder->status = SalesOrderStatusEnum::DRAFT;
            $salesOrder->fulfillment_status = SalesOrderFulfillmentStatusEnum::UNFULFILLED;
            $salesOrder->invoicing_status = SalesOrderInvoicingStatusEnum::UNINVOICED;
            $salesOrder->order_date = Carbon::today()->toDateString();
            $salesOrder->expected_delivery_date = null;
            $salesOrder->payment_terms_days = (int) $quotation->payment_terms_days;
            $salesOrder->subtotal = (string) $quotation->subtotal;
            $salesOrder->discount_amount = (string) $quotation->discount_amount;
            $salesOrder->tax_amount = (string) $quotation->tax_amount;
            $salesOrder->shipping_amount = '0.00';
            $salesOrder->total_amount = (string) $quotation->total_amount;
            $salesOrder->reserved_credit_amount = '0.00';
            $salesOrder->customer_snapshot_json = $quotation->customer_snapshot_json;
            $salesOrder->idempotency_key = $key;
            $salesOrder->created_by_user_id = $actor->id;
            $salesOrder->notes = $quotation->notes;
            $salesOrder->save();

            $quotation->loadMissing('lines');
            foreach ($quotation->lines as $qLine) {
                $soLine = new SalesOrderLine;
                $soLine->tenant_id = $tenantId;
                $soLine->sales_order_id = $salesOrder->id;
                $soLine->line_number = $qLine->line_number;
                $soLine->product_id = $qLine->product_id;
                $soLine->variant_id = $qLine->variant_id;
                $soLine->uom_id = $qLine->uom_id;
                $soLine->description = $qLine->description;
                $soLine->ordered_qty = $qLine->quantity;
                $soLine->reserved_qty = '0.0000';
                $soLine->fulfilled_qty = '0.0000';
                $soLine->cancelled_qty = '0.0000';
                $soLine->invoiced_qty = '0.0000';
                $soLine->returned_qty = '0.0000';
                $soLine->base_unit_price = $qLine->base_unit_price;
                $soLine->unit_price = $qLine->unit_price;
                $soLine->price_list_id = $qLine->price_list_id;
                $soLine->pricing_source = $qLine->pricing_source;
                $soLine->discount_amount = $qLine->discount_amount;
                $soLine->tax_rate = $qLine->tax_rate;
                $soLine->tax_amount = $qLine->tax_amount;
                $soLine->line_total = $qLine->line_total;
                $soLine->save();
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'sales_order.convert_from_quotation',
                userId: $actor->id,
                storeId: $salesOrder->store_id,
                subjectType: SalesOrder::class,
                subjectId: $salesOrder->id,
                description: "Sales Order {$salesOrder->so_number} berhasil dibuat dari Quotation {$quotation->quotation_number}",
                newValues: $salesOrder->toArray()
            );

            return $salesOrder->fresh(['lines', 'customer']);
        });
    }

    /**
     * Konfirmasi Sales Order (CONFIRMED):
     * Mereservasi eksposur kredit dan kuantitas stok pada setiap baris pesanan.
     */
    public function confirmOrder(
        SalesOrder $salesOrder,
        User $actor,
        bool $allowOverride = false,
        ?string $overrideReason = null
    ): SalesOrder {
        return DB::transaction(function () use ($salesOrder, $actor, $allowOverride, $overrideReason) {
            $locked = SalesOrder::withoutGlobalScopes()
                ->where('tenant_id', $salesOrder->tenant_id)
                ->where('id', $salesOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [SalesOrderStatusEnum::DRAFT, SalesOrderStatusEnum::PENDING_CREDIT_APPROVAL], true)) {
                throw new DomainException("Hanya Sales Order berstatus DRAFT atau PENDING_CREDIT_APPROVAL yang dapat dikonfirmasi. Status saat ini: {$locked->status->value}");
            }

            // 1. Evaluasi & Reservasi Eksposur Kredit (B2B Credit Control - Lock Order Step 2 A0/A8)
            $creditAccount = $this->customerCreditService->getOrCreateCreditAccount($locked->customer_id, (int) $locked->tenant_id);
            if ($locked->payment_terms_days > 0 || bccomp((string) $creditAccount->credit_limit, '0.00', 2) > 0) {
                $this->customerCreditService->reserveOrderExposure($locked, $actor, $allowOverride, $overrideReason);
            }

            // 2. Reservasi Stok
            $locked->loadMissing('lines');
            foreach ($locked->lines as $line) {
                $line->reserved_qty = (string) $line->ordered_qty;
                $line->save();
            }

            $locked->status = SalesOrderStatusEnum::CONFIRMED;
            $locked->confirmed_by_user_id = $actor->id;
            $locked->confirmed_at = Carbon::now();
            $locked->save();

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'sales_order.confirm',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: SalesOrder::class,
                subjectId: $locked->id,
                description: "Sales Order {$locked->so_number} telah dikonfirmasi dan mereservasi stok item.",
                newValues: ['status' => $locked->status->value, 'confirmed_by' => $actor->id]
            );

            return $locked->fresh(['lines', 'customer']);
        });
    }

    /**
     * Memenuhi (Fulfill) sebagian atau seluruh item pada Sales Order:
     * - Memvalidasi sisa kuantitas yang dapat dipenuhi (Anti-Over-Fulfillment).
     * - Membuat dokumen eksekusi Order operasional (orders.sales_order_id).
     * - Memotong stok aktual melalui InventoryLedgerService (M4).
     * - Memperbarui fulfilled_qty dan melepaskan reserved_qty proporsional.
     * - Memperbarui fulfillment_status (PARTIALLY_FULFILLED atau FULFILLED).
     *
     * @param  array<int, array{line_id: int, quantity: float|string}>  $fulfillmentLines
     */
    public function fulfillOrder(SalesOrder $salesOrder, array $fulfillmentLines, User $actor, ?string $idempotencyKey = null): Order
    {
        return DB::transaction(function () use ($salesOrder, $fulfillmentLines, $actor, $idempotencyKey) {
            $tenantId = (int) $salesOrder->tenant_id;
            $storeId = (int) $salesOrder->store_id;

            // 1. Kunci Sales Order induk
            $lockedSO = SalesOrder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $salesOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedSO->status, [SalesOrderStatusEnum::CONFIRMED, SalesOrderStatusEnum::PARTIALLY_FULFILLED], true)) {
                throw new DomainException("Hanya Sales Order berstatus CONFIRMED atau PARTIALLY_FULFILLED yang dapat dipenuhi. Status saat ini: {$lockedSO->status->value}");
            }

            if (empty($fulfillmentLines)) {
                throw ValidationException::withMessages([
                    'fulfillment_lines' => ['Minimal 1 baris item harus ditentukan untuk pemenuhan pesanan.'],
                ]);
            }

            // 2. Kunci seluruh baris SO (ORDER BY id ASC - Lock Order A0/A8)
            $lines = SalesOrderLine::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sales_order_id', $lockedSO->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $orderSubtotal = '0.00';
            $orderDiscount = '0.00';
            $orderTax = '0.00';
            $orderTotal = '0.00';

            $validatedFulfillments = [];

            foreach ($fulfillmentLines as $fItem) {
                $lineId = (int) ($fItem['line_id'] ?? 0);
                /** @var SalesOrderLine|null $targetLine */
                $targetLine = $lines->get($lineId);

                if ($targetLine === null) {
                    throw ValidationException::withMessages([
                        'fulfillment_lines' => ["Baris SO ID #{$lineId} tidak ditemukan pada pesanan ini."],
                    ]);
                }

                $fulfillQty = bcadd((string) ($fItem['quantity'] ?? '0'), '0', 4);
                if (bccomp($fulfillQty, '0', 4) <= 0) {
                    continue;
                }

                $remainingQty = $targetLine->getRemainingQty();
                if (bccomp($fulfillQty, $remainingQty, 4) > 0) {
                    throw new DomainException("Kuantitas pemenuhan ({$fulfillQty}) melebihi sisa pesanan ({$remainingQty}) pada baris #{$targetLine->line_number} ({$targetLine->description}).");
                }

                // Kalkulasi proporsional nilai finansial baris yang dipenuhi
                $lineSub = bcmul($fulfillQty, (string) $targetLine->unit_price, 2);
                $unitDiscount = bccomp((string) $targetLine->ordered_qty, '0', 4) > 0
                    ? bcdiv((string) $targetLine->discount_amount, (string) $targetLine->ordered_qty, 4)
                    : '0.0000';
                $lineDisc = bcmul($fulfillQty, $unitDiscount, 2);

                $netLine = bcsub($lineSub, $lineDisc, 2);
                $lineTax = bccomp((string) $targetLine->tax_rate, '0', 2) > 0
                    ? bcdiv(bcmul($netLine, (string) $targetLine->tax_rate, 4), '100', 2)
                    : '0.00';

                $lineTot = bcadd($netLine, $lineTax, 2);

                $orderSubtotal = bcadd($orderSubtotal, $lineSub, 2);
                $orderDiscount = bcadd($orderDiscount, $lineDisc, 2);
                $orderTax = bcadd($orderTax, $lineTax, 2);
                $orderTotal = bcadd($orderTotal, $lineTot, 2);

                $validatedFulfillments[] = [
                    'line' => $targetLine,
                    'fulfill_qty' => $fulfillQty,
                    'subtotal' => $lineSub,
                    'discount' => $lineDisc,
                    'tax' => $lineTax,
                    'total' => $lineTot,
                ];
            }

            if (empty($validatedFulfillments)) {
                throw new DomainException('Tidak ada kuantitas valid (> 0) untuk dipenuhi.');
            }

            // 3. Buat dokumen eksekusi Order operasional (orders)
            $orderCode = $this->sequenceService->nextNumber($tenantId, 'ORD', $storeId);
            $orderKey = $idempotencyKey ?: "so_fulfill_{$lockedSO->id}_".Str::random(10);

            $order = new Order;
            $order->user_id = $actor->id;
            $order->order_code = $orderCode;
            $order->idempotency_key = $orderKey;
            $order->order_date = Carbon::today()->toDateString();
            $order->tenant_id = $tenantId;
            $order->store_id = $storeId;
            $order->customer_id = $lockedSO->customer_id;
            $order->sales_order_id = $lockedSO->id;
            $order->order_type = 'delivery';
            $order->order_status = OrderStatus::Pending->value;
            $order->source_reference = "SO:{$lockedSO->so_number}".($lockedSO->customer_po_number ? " | PO:{$lockedSO->customer_po_number}" : '');
            $order->subtotal_amount = (int) round((float) $orderSubtotal);
            $order->discount_amount = (int) round((float) $orderDiscount);
            $order->tax_amount = (int) round((float) $orderTax);
            $order->order_amount = (int) round((float) $orderTotal);
            $order->save();

            // 4. Buat OrderItem & mutasi inventori di M4
            $movementItems = [];
            foreach ($validatedFulfillments as $val) {
                /** @var SalesOrderLine $line */
                $line = $val['line'];
                $fQty = $val['fulfill_qty'];

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->variant_id,
                    'qty' => (int) ceil((float) $fQty),
                    'order_price' => (float) $line->unit_price,
                    'order_subtotal' => (float) $val['subtotal'],
                    'item_discount_amount' => (float) $val['discount'],
                    'net_line_total' => (float) $val['total'],
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'notes' => "SO #{$lockedSO->so_number} Line #{$line->line_number}",
                ]);

                // Mutasi stok M4 (SaleDeduction)
                $movementItems[] = [
                    'store_id' => $storeId,
                    'item_type' => 'product',
                    'item_id' => (int) $line->product_id,
                    'quantity_delta' => "-{$fQty}",
                    'type' => StockMovementType::SaleDeduction->value,
                    'unit_cost' => null,
                    'reason' => "Fulfillment SO #{$lockedSO->so_number} Line #{$line->line_number}",
                    'idempotency_key' => "so_fulfill:{$tenantId}:{$lockedSO->id}:{$line->id}:{$order->id}",
                    'order_id' => $order->id,
                    'order_item_id' => $orderItem->id,
                    'user_id' => $actor->id,
                ];

                // Update progres baris SO
                $line->fulfilled_qty = bcadd((string) $line->fulfilled_qty, $fQty, 4);
                $newReserved = bcsub((string) $line->reserved_qty, $fQty, 4);
                $line->reserved_qty = bccomp($newReserved, '0', 4) > 0 ? $newReserved : '0.0000';
                $line->save();
            }

            // Eksekusi mutasi stok M4
            $this->inventoryLedgerService->recordBatchMovements($tenantId, $movementItems);

            // 5. Evaluasi status fulfillment SO induk
            $allCompleted = true;
            $anyFulfilled = false;

            $freshLines = SalesOrderLine::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sales_order_id', $lockedSO->id)
                ->get();

            foreach ($freshLines as $l) {
                $rem = $l->getRemainingQty();
                if (bccomp($rem, '0', 4) > 0) {
                    $allCompleted = false;
                }
                if (bccomp((string) $l->fulfilled_qty, '0', 4) > 0) {
                    $anyFulfilled = true;
                }
            }

            if ($allCompleted) {
                $lockedSO->fulfillment_status = SalesOrderFulfillmentStatusEnum::FULFILLED;
                $lockedSO->status = SalesOrderStatusEnum::FULFILLED;
            } elseif ($anyFulfilled) {
                $lockedSO->fulfillment_status = SalesOrderFulfillmentStatusEnum::PARTIALLY_FULFILLED;
                $lockedSO->status = SalesOrderStatusEnum::PARTIALLY_FULFILLED;
            }
            $lockedSO->save();

            // 6. Jejak Audit
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'sales_order.fulfill',
                userId: $actor->id,
                storeId: $storeId,
                subjectType: SalesOrder::class,
                subjectId: $lockedSO->id,
                description: "Fulfillment pesanan {$lockedSO->so_number} diproses melalui Order #{$order->order_code}. Status: {$lockedSO->status->value}",
                newValues: [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'fulfillment_status' => $lockedSO->fulfillment_status->value,
                    'status' => $lockedSO->status->value,
                ]
            );

            return $order->fresh(['orderItems']);
        });
    }

    /**
     * Membatalkan sisa pesanan Sales Order:
     * - Melepaskan sisa reservasi stok (reserved_qty = 0).
     * - Mengalihkan sisa ke cancelled_qty.
     * - Mengubah status ke CANCELLED (jika belum ada pemenuhan) atau CLOSED (jika sudah ada parsial).
     */
    public function cancelOrder(SalesOrder $salesOrder, User $actor, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $actor, $reason) {
            $locked = SalesOrder::withoutGlobalScopes()
                ->where('tenant_id', $salesOrder->tenant_id)
                ->where('id', $salesOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [SalesOrderStatusEnum::CLOSED, SalesOrderStatusEnum::CANCELLED, SalesOrderStatusEnum::FULFILLED], true)) {
                throw new DomainException("Sales Order dalam status {$locked->status->value} tidak dapat dibatalkan.");
            }

            $locked->loadMissing('lines');
            $hasAnyFulfillment = false;

            foreach ($locked->lines as $line) {
                $remaining = $line->getRemainingQty();
                if (bccomp($remaining, '0', 4) > 0) {
                    $line->cancelled_qty = bcadd((string) $line->cancelled_qty, $remaining, 4);
                    $line->reserved_qty = '0.0000';
                    $line->save();
                }

                if (bccomp((string) $line->fulfilled_qty, '0', 4) > 0) {
                    $hasAnyFulfillment = true;
                }
            }

            // Melepaskan eksposur kredit jika ada
            $this->customerCreditService->releaseOrderExposure($locked, $actor, $reason);

            if ($hasAnyFulfillment) {
                $locked->status = SalesOrderStatusEnum::CLOSED;
            } else {
                $locked->status = SalesOrderStatusEnum::CANCELLED;
            }

            $locked->cancelled_by_user_id = $actor->id;
            $locked->cancelled_at = Carbon::now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'sales_order.cancel',
                userId: $actor->id,
                storeId: $locked->store_id,
                subjectType: SalesOrder::class,
                subjectId: $locked->id,
                description: "Sales Order {$locked->so_number} dibatalkan/ditutup. Alasan: {$reason}",
                newValues: [
                    'status' => $locked->status->value,
                    'cancellation_reason' => $reason,
                ]
            );

            return $locked->fresh(['lines', 'customer']);
        });
    }

    /**
     * Membuat Repeat Order dari Sales Order yang sudah ada sebelumnya.
     * Mengambil item dari order lama, namun MENGHITUNG ULANG harga dan pajak terbaru
     * menggunakan CatalogPriceResolverService per tanggal hari ini.
     */
    public function createRepeatOrder(int|SalesOrder $sourceOrder, User $actor): SalesOrder
    {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($sourceOrder, $actor, $tenantId) {
            $source = $sourceOrder instanceof SalesOrder
                ? $sourceOrder
                : SalesOrder::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($sourceOrder);

            $source->loadMissing(['lines', 'customer']);

            $freshItems = [];
            foreach ($source->lines as $line) {
                $product = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $line->product_id)
                    ->first();

                if ($product === null || ! $product->is_active) {
                    throw new DomainException("Produk '{$line->description}' sudah tidak aktif dan tidak dapat dimasukkan ke pemesanan ulang (Repeat Order).");
                }

                $freshItems[] = [
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'uom_id' => $line->uom_id,
                    'quantity' => $line->ordered_qty,
                    'description' => $line->description,
                ];
            }

            $payload = [
                'customer_id' => $source->customer_id,
                'store_id' => $source->store_id,
                'billing_address_id' => $source->billing_address_id,
                'shipping_address_id' => $source->shipping_address_id,
                'order_source' => 'REPEAT_ORDER',
                'customer_po_number' => $source->customer_po_number ? 'REPEAT-'.$source->customer_po_number : null,
                'payment_terms_days' => $source->payment_terms_days,
                'notes' => "Pemesanan ulang otomatis (Repeat Order) dari SO #{$source->so_number}",
                'items' => $freshItems,
            ];

            $newSO = $this->createOrder($payload, $actor);
            $newSO->source_repeat_order_id = $source->id;
            $newSO->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'sales_order.repeat_order',
                userId: $actor->id,
                storeId: $newSO->store_id,
                subjectType: SalesOrder::class,
                subjectId: $newSO->id,
                description: "Repeat Order {$newSO->so_number} dibuat dari sumber SO #{$source->so_number}",
                newValues: ['source_repeat_order_id' => $source->id]
            );

            return $newSO;
        });
    }
}
