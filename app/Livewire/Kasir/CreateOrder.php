<?php

// Refactor Kiosk Kasir: dukungan pemilihan pelanggan (loyalti)
// dan Split Payment exact-sum via TransactionService::createTransaction().
// Skenario "Tarik Pesanan" TETAP memakai pembayaran tunggal lama -- lihat
// SYNC ALERT Node 1 untuk rencana migrasinya ke split payment. | 2026-09-22

namespace App\Livewire\Kasir;

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Approval;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\ParkedCart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Table;
use App\Models\UnitOfMeasure;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Services\VoucherService;
use App\Modules\FNB\Services\SplitBillService;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationBreakdownDto;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationInputDto;
use App\Modules\Pricing\Services\DiscountApprovalService;
use App\Modules\Pricing\Services\UnifiedPricingCalculationPipelineService;
use App\Services\CartService;
use App\Services\CatalogPriceResolverService;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use App\Services\MenuCacheService;
use App\Services\TransactionService;
use App\Support\Traits\RequiresActiveStoreContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

// Layar POS kasir memakai noPadding agar tidak membawa padding admin generik
// yang membuang ruang di layar yang sudah sempit dipecah 3 kolom (§5.4 audit).
#[Layout('layouts.app', ['noPadding' => true])]
class CreateOrder extends Component
{
    use RequiresActiveStoreContext;
    use RequiresTenantContext;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Admin'], true)) {
            abort(403, 'You do not have permission to access the POS terminal.');
        }

        if (Auth::user()->pin_hash === null) {
            session()->flash('warning', 'Cashiers must configure a PIN before opening a register shift or processing transactions.');
        }
    }

    public ?string $orderType = null;   // 'dine_in' | 'takeaway'

    public ?int $tableId = null;

    public array $cart = [];            // payload MENTAH, sama format dgn self-order

    public float $cashReceived = 0;     // HANYA dipakai skenario Tarik Pesanan (pembayaran tunggal)

    public string $search = '';

    public string $paymentMethod = 'cash'; // HANYA dipakai skenario Tarik Pesanan

    // Properti baru untuk mode "Retrieve Order"
    #[Locked]
    public ?string $pendingOrderCode = null;

    #[Locked]
    public ?int $pendingOrderId = null;

    // state modal pemilihan modifier / konfigurator adaptif
    #[Locked]
    public ?int $selectingProductId = null;

    // state modal pemilihan varian produk ritel (TASK-P0-01)
    #[Locked]
    public ?int $selectingVariantProductId = null;

    public ?int $pendingVariantId = null;

    public ?int $pendingUomId = null;

    // state modal split bill meja (TASK-P1-01)
    public bool $showSplitBillModal = false;
    public string $splitBillMode = 'equal'; // 'equal' | 'amount' | 'item'
    public int $splitBillParts = 2;
    /** @var array<int, int|float|string> */
    public array $splitBillAmounts = [];
    /** @var array<int, int> */
    public array $splitBillItemAssignments = [];
    public ?int $splitBillOrderId = null;

    public array $pendingModifierIds = [];

    public int $pendingQty = 1;

    public ?string $pendingNotes = null;

    // Customer selection (Loyalty) -- HANYA berlaku untuk
    // skenario Walk-in Baru (lihat SYNC ALERT #3 di Phase 1 untuk alasan
    // skenario Tarik Pesanan belum mendukung ini).
    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?string $customerName = null;

    public string $customerSearch = '';

    public ?int $selectedDiscountId = null;

    // Parked / Held Cart State
    public bool $isParkModalOpen = false;

    public bool $isParkedListOpen = false;

    public string $holdReference = '';

    public string $holdLabel = '';

    public string $holdNotes = '';

    public array $resumeWarnings = [];

    /**
     * @var array<int, array{method: string, amount: int, card_number?: string|null, reference_number?: string|null, pin?: string|null}>
     */
    public array $paymentLegs = [];

    public $paymentTendered = 0;

    public array $itemDiscounts = [];

    // Manual Discount Override State
    public bool $isManualDiscountModalOpen = false;

    public int $manualDiscountAmount = 0;

    public $manualDiscountAmountInput = null;

    public string $manualDiscountReason = '';

    public int $appliedManualDiscount = 0;

    public string $appliedManualDiscountReason = '';

    public ?int $manualDiscountApprovalId = null;

    // Stored-Value Instruments (Gift Card & Store Credit)
    public string $giftCardNumberInput = '';

    public string $giftCardPinInput = '';

    public float $storeCreditBalance = 0.0;

    // Voucher Lifecycle State
    public ?string $voucherCode = null;

    public string $voucherInput = '';

    public ?string $voucherError = null;

    public function applyVoucher(): void
    {
        $this->voucherError = null;
        $code = strtoupper(trim($this->voucherInput));
        if (empty($code)) {
            $this->voucherError = 'Voucher code cannot be empty.';

            return;
        }

        try {
            $customer = $this->customerId ? Customer::find($this->customerId) : null;
            $subtotal = (string) collect($this->cart)->sum(fn ($i) => (float) ($i['total_price'] ?? ($i['price'] ?? 0) * ($i['qty'] ?? 1)));
            $validation = app(VoucherService::class)
                ->validateVoucher($code, $customer, $subtotal);

            app(VoucherService::class)->reserveVoucher(
                code: $code,
                cartUuid: $this->idempotencyKey,
                storeId: (int) ($this->activeStoreId ?? 1),
                customerId: $this->customerId ? (int) $this->customerId : null,
                reservedAmount: (float) $validation['discount_amount']
            );

            $this->voucherCode = $code;
            $this->voucherInput = '';
            session()->flash('success', "Voucher '{$code}' applied successfully.");
        } catch (\Throwable $e) {
            $this->voucherError = $e->getMessage();
        }
    }

    public function removeVoucher(): void
    {
        if ($this->voucherCode) {
            app(VoucherService::class)->releaseVoucher($this->idempotencyKey);
            $this->voucherCode = null;
            $this->voucherError = null;
        }
    }

    #[Locked]
    public string $idempotencyKey;

    #[Locked]
    public ?int $deviceId = null;

    #[Locked]
    public ?int $shiftId = null;

    #[Locked]
    public ?int $activeStoreId = null;

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();
        $this->activeStoreId = app(TenantContext::class)->getStoreId();

        $user = Auth::user();
        if ($user) {
            $activeShift = Shift::where(function ($q) use ($user) {
                $q->where('active_cashier_id', $user->id)
                    ->orWhere(function ($sub) use ($user) {
                        $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                    });
            })->where('status', 'open')->first();

            if ($activeShift) {
                $this->shiftId = $activeShift->id;
                $this->deviceId = $activeShift->device_id;
            }
        }
    }

    public function updatedOrderType(): void
    {
        if ($this->orderType === 'takeaway' || ! $this->isTablesEnabled()) {
            $this->tableId = null; // dropdown meja disembunyikan otomatis via Blade
        }
    }

    public function setOrderType(string $type): void
    {
        $this->orderType = in_array($type, ['dine_in', 'takeaway']) ? $type : 'dine_in';
        $this->updatedOrderType();
    }

    public function updatedSearch(): void
    {
        // Jika input pencarian adalah Kode Pesanan (dimulai dengan POS-)
        if (str_starts_with(strtoupper($this->search), 'POS-')) {
            $this->loadPendingOrder(strtoupper($this->search));
            $this->search = ''; // bersihkan input
        }
    }

    public function loadPendingOrder(string $orderCode): void
    {
        $order = Order::with('orderItems.product')
            ->where('order_code', $orderCode)
            ->where('order_status', 'pending')
            ->where('payment_method', 'cash')
            ->first();

        if (! $order) {
            $this->dispatch('toast', message: 'Order not found or has already been paid.', type: 'error');

            return;
        }

        // Pindahkan data pesanan ke cart kasir
        $this->cart = [];
        foreach ($order->orderItems as $item) {
            $this->cart[] = [
                'product_id' => $item->product_id,
                'qty' => $item->qty,
                'modifier_ids' => collect($item->options)->pluck('modifier_id')->filter()->values()->toArray(),
                'notes' => $item->notes,
            ];
        }

        // Skenario ini tidak mendukung customer_id/loyalti --
        // pastikan tidak ada sisa pilihan pelanggan dari sesi sebelumnya.
        $this->customerId = null;
        $this->customerName = null;
        $this->selectedDiscountId = null;

        $this->pendingOrderCode = $order->order_code;
        $this->pendingOrderId = $order->id;
        $this->orderType = $order->order_type?->value;
        $this->tableId = $order->table_id;

        $this->dispatch('toast', message: 'Order '.$orderCode.' retrieved successfully.', type: 'success');
    }

    public function cancelPendingOrderMode(): void
    {
        $this->pendingOrderCode = null;
        $this->pendingOrderId = null;
        $this->cart = [];
        $this->dispatch('pos-cart-state-changed', dirty: false);
        $this->orderType = null;
        $this->tableId = null;
        // Hygiene fix (lihat Phase 1 self-adversarial review):
        // customer terpilih tidak boleh ikut terbawa ke pesanan berikutnya.
        $this->customerId = null;
        $this->customerName = null;
        $this->selectedDiscountId = null;
        $this->idempotencyKey = (string) Str::uuid();
    }

    #[Computed]
    public function isTablesEnabled(): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();
        if (! $tenantId || ! $storeId) {
            return false;
        }

        return app(EntitlementService::class)->isTablesEnabled($tenantId, $storeId);
    }

    #[Computed]
    public function activeTables()
    {
        if (! $this->isTablesEnabled()) {
            return collect();
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $query = Table::query()
            ->where('is_active', true)
            ->where('operational_status', '!=', 'disabled');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        return $query->orderBy('table_name')->get();
    }

    #[Computed]
    public function categories()
    {
        // OPTIMASI:
        // 1. MenuCacheService::getCatalog() pakai Cache::remember (Laravel Cache, TTL 1 jam).
        // 2. #[Computed] mencegah query/cache-lookup diulang dalam siklus request Livewire yang sama.
        return app(MenuCacheService::class)->getCatalog();
    }

    // Pencarian pelanggan untuk Loyalty. Query DB tetap
    // dipertahankan server-side (tidak bisa full-client) tapi dibatasi
    // debounce 300ms + minimal 2 karakter di sisi Blade agar sesuai
    // prinsip Zero-Latency Concurrency sebisa mungkin.
    #[Computed]
    public function customerResults()
    {
        $q = trim($this->customerSearch);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        return Customer::query()
            ->where('is_active', true)
            ->where(fn ($qq) => $qq->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'phone']);
    }

    public function selectCustomer(int $id, string $name): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $customerExists = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('is_active', true)
            ->exists();

        if (! $customerExists) {
            $this->storeCreditBalance = 0.0;

            return;
        }

        $this->customerId = $id;
        $this->customerName = $name;
        $this->customerSearch = '';

        $sc = StoreCreditAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $id)
            ->where('status', StoreCreditStatusEnum::ACTIVE)
            ->first();

        $this->storeCreditBalance = $sc ? (float) $sc->current_balance : 0.0;
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->customerName = null;
        $this->storeCreditBalance = 0.0;
    }

    public function addGiftCardPaymentLeg(string $cardNumber, int $amount, ?string $pin = null): void
    {
        $this->paymentLegs[] = [
            'method' => 'gift_card',
            'amount' => $amount,
            'card_number' => $cardNumber,
            'reference_number' => $cardNumber,
            'pin' => $pin,
        ];
    }

    public function addStoreCreditPaymentLeg(int $amount): void
    {
        $this->paymentLegs[] = [
            'method' => 'store_credit',
            'amount' => $amount,
        ];
    }

    /**
     * Menghitung rincian kalkulasi harga kasir secara real-time via UnifiedPricingCalculationPipelineService.
     */
    public function getCalculationBreakdown(): CheckoutCalculationBreakdownDto
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $storeId = (int) (app(TenantContext::class)->getStoreId() ?? $this->activeStoreId ?? 1);

        $items = [];
        foreach ($this->cart as $raw) {
            $items[] = [
                'product_id' => (int) $raw['product_id'],
                'product_variant_id' => $raw['product_variant_id'] ?? null,
                'uom_id' => $raw['uom_id'] ?? null,
                'quantity' => (float) ($raw['qty'] ?? 1),
                'custom_price' => isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                'modifier_ids' => $raw['modifier_ids'] ?? [],
                'item_discount_amount' => (float) ($raw['item_discount_amount'] ?? 0),
                'item_discount_id' => $raw['item_discount_id'] ?? null,
            ];
        }

        $input = new CheckoutCalculationInputDto(
            tenantId: $tenantId,
            storeId: $storeId,
            channel: 'pos_cashier',
            customerId: $this->customerId,
            items: $items,
            voucherCode: $this->voucherCode ?? null,
            discountId: $this->selectedDiscountId,
            manualDiscountAmount: (float) $this->appliedManualDiscount,
            hasCashPayment: $this->paymentMethod === 'cash' || collect($this->paymentLegs)->contains('method', 'cash')
        );

        return app(UnifiedPricingCalculationPipelineService::class)->calculate($input);
    }

    // Modal state reset
    public function openModifierPicker(int $productId): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        $product = Product::with([
            'modifierGroups.modifiers',
            'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues'),
            'unitConversions.fromUom',
            'baseUom',
        ])->findOrFail($productId);

        $storeId = app(TenantContext::class)->getStoreId();
        $resolver = app(CatalogPriceResolverService::class);
        if (! $resolver->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        $this->selectingProductId = $product->id;
        $activeVariants = $product->variants->where('is_active', true);
        $this->selectingVariantProductId = $activeVariants->isNotEmpty() ? $product->id : null;

        $this->pendingVariantId = null;
        $this->pendingUomId = $product->base_uom_id ?? null;
        $this->pendingModifierIds = [];
        $this->pendingQty = 1;
        $this->pendingNotes = null;

        // Pre-select default modifiers per group
        foreach ($product->modifierGroups as $group) {
            foreach ($group->modifiers as $mod) {
                if ($mod->is_default && ($mod->is_active ?? true)) {
                    $this->pendingModifierIds[] = $mod->id;
                    if ($group->selection_type === 'single') {
                        break; // only select the first default for single selection
                    }
                }
            }
        }
    }

    public function scanBarcode(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        // 1. Cek ProductVariant terlebih dahulu (SKU atau Barcode) (TASK-P0-01)
        $variant = ProductVariant::with(['product.modifierGroups.modifiers', 'attributeValues'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                    ->orWhere('sku', $code);
            })
            ->first();

        if ($variant && $variant->product) {
            $product = $variant->product;
            $storeId = app(TenantContext::class)->getStoreId();
            $resolver = app(CatalogPriceResolverService::class);
            if (! $resolver->isAvailableInStore($product, $storeId)) {
                $this->dispatch('toast', message: "Product '{$product->product_name}' ({$variant->variant_name}) is not available at this branch.", type: 'warning');

                return;
            }

            $this->addVariantToCart($variant);
            $this->dispatch('toast', message: "Added: {$product->product_name} ({$variant->variant_name})", type: 'success');

            return;
        }

        // 2. Fallback ke Product master
        $product = Product::with(['modifierGroups', 'variants'])->where('tenant_id', $tenantId)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                    ->orWhere('product_code', $code);
            })
            ->where('is_active', true)
            ->first();

        if ($product) {
            $product->loadMissing(['unitConversions', 'modifierGroups.modifiers', 'variants' => fn ($q) => $q->where('is_active', true)]);
            $hasActiveVariants = $product->variants->where('is_active', true)->isNotEmpty();
            $hasModifierGroups = $product->modifierGroups->isNotEmpty();
            $hasUnitConversions = $product->unitConversions->isNotEmpty();

            if ($hasActiveVariants || $hasModifierGroups || $hasUnitConversions) {
                $this->openModifierPicker($product->id);

                return;
            }

            $this->addToCartDirectly($product->id);
            $this->dispatch('toast', message: "Added: {$product->product_name}", type: 'success');
        } else {
            // 3. Fallback ke GS1 Digital Scale Barcode Parser (TASK-P2-02)
            $barcodeService = app(\App\Modules\Retail\Services\RetailBarcodeService::class);
            $scaleData = $barcodeService->parseScaleBarcode($code);
            if ($scaleData !== null) {
                $itemCode = $scaleData['item_code']; // 5 digit PLU
                $scaleProduct = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->where(function ($q) use ($itemCode) {
                        $q->where('product_code', $itemCode)
                            ->orWhere('product_code', 'PLU-'.$itemCode)
                            ->orWhere('barcode', 'like', "%{$itemCode}%");
                    })
                    ->first();

                if ($scaleProduct) {
                    $storeId = app(TenantContext::class)->getStoreId();
                    $resolver = app(CatalogPriceResolverService::class);
                    if (! $resolver->isAvailableInStore($scaleProduct, $storeId)) {
                        $this->dispatch('toast', message: "Produk timbangan '{$scaleProduct->product_name}' tidak tersedia di cabang ini.", type: 'warning');

                        return;
                    }

                    $this->addScaleProductToCart($scaleProduct, $scaleData);
                    $this->dispatch('toast', message: "Produk timbangan: {$scaleProduct->product_name} ({$scaleData['weight_kg']} kg)", type: 'success');

                    return;
                }
            }

            $this->dispatch('toast', message: "Barcode not found: {$code}", type: 'warning');
        }
    }

    public function openVariantPicker(int $productId): void
    {
        $this->openModifierPicker($productId);
    }

    public function closeVariantPicker(): void
    {
        $this->selectingVariantProductId = null;
        if (! $this->selectingProductId) {
            $this->pendingVariantId = null;
        }
    }

    public function selectVariant(int $variantId): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $variant = ProductVariant::with(['product.modifierGroups.modifiers', 'attributeValues'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($variantId);

        if (! $variant || ! $variant->product) {
            $this->dispatch('toast', message: 'Varian produk tidak ditemukan atau tidak aktif.', type: 'warning');

            return;
        }

        $this->pendingVariantId = $variant->id;

        $product = $variant->product;
        $hasRequiredModifiers = $product->modifierGroups()->where('is_required', true)->exists();

        // Jika tidak ada modifier wajib, selesaikan pemilihan langsung
        if (! $hasRequiredModifiers) {
            $this->closeModifierPicker();
            $this->closeVariantPicker();
            $this->addVariantToCart($variant);
            $this->dispatch('toast', message: "Added: {$variant->product->product_name} ({$variant->variant_name})", type: 'success');

            return;
        }
    }

    public function setPendingVariant(?int $variantId): void
    {
        $this->pendingVariantId = $variantId;
    }

    public function setPendingUom(?int $uomId): void
    {
        $this->pendingUomId = $uomId;
    }

    public function setPendingQty(int $qty): void
    {
        $this->pendingQty = max(1, $qty);
    }

    public function decreasePendingQty(): void
    {
        $this->pendingQty = max(1, $this->pendingQty - 1);
    }

    public function increasePendingQty(): void
    {
        $this->pendingQty++;
    }

    public function addVariantToCart(ProductVariant $variant): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        $product = $variant->product;
        if (! $product) {
            return;
        }

        if ($product->modifierGroups()->where('is_required', true)->exists()) {
            $this->openModifierPicker($product->id);
            $this->pendingVariantId = $variant->id;

            return;
        }

        foreach ($this->cart as $index => $item) {
            if (
                $item['product_id'] === $product->id &&
                ($item['product_variant_id'] ?? null) === $variant->id &&
                empty($item['modifier_ids']) &&
                empty($item['notes'])
            ) {
                $this->cart[$index]['qty']++;
                $this->dispatch('pos-cart-state-changed', dirty: true);

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
            'modifier_ids' => [],
            'notes' => null,
        ];
        $this->dispatch('pos-cart-state-changed', dirty: true);
    }

    /**
     * Menambahkan item timbangan digital supermarket ke keranjang belanja (TASK-P2-02).
     *
     * @param array{is_scale: bool, prefix: string, item_code: string, weight_grams: int, weight_kg: numeric-string, embedded_price: int, check_digit: int} $scaleData
     */
    public function addScaleProductToCart(Product $product, array $scaleData): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        if ($scaleData['prefix'] === '02') {
            // Weight-embedded: bobot kilogram dikalikan harga per KG produk
            $basePrice = (float) $product->product_price;
            $linePrice = (float) bcmul((string) $scaleData['weight_kg'], (string) $basePrice, 2);
            $notes = "Timbangan: {$scaleData['weight_kg']} kg ({$scaleData['weight_grams']}g)";
        } else {
            // Price-embedded: nominal harga rupiah tercetak langsung di barcode
            $linePrice = (float) $scaleData['embedded_price'];
            $notes = 'Timbangan: Rp '.number_format($linePrice, 0, ',', '.');
        }

        $this->cart[] = [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'qty' => 1,
            'modifier_ids' => [],
            'notes' => $notes,
            'custom_price' => $linePrice,
        ];

        $this->dispatch('pos-cart-state-changed', dirty: true);
    }

    public function openSplitBillModal(?int $orderId = null): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $targetOrderId = $orderId ?? $this->pendingOrderId;

        if (! $targetOrderId && $this->tableId) {
            $tableOrder = Order::where('tenant_id', $tenantId)
                ->where('table_id', $this->tableId)
                ->whereNotIn('order_status', ['paid', 'completed', 'void', 'cancelled'])
                ->latest()
                ->first();
            if ($tableOrder) {
                $targetOrderId = $tableOrder->id;
            }
        }

        if (! $targetOrderId) {
            $this->dispatch('toast', message: 'Pilih meja dengan pesanan aktif atau muat pesanan tertunda terlebih dahulu untuk Split Bill.', type: 'warning');

            return;
        }

        $order = Order::with('orderItems.product')->where('tenant_id', $tenantId)->find($targetOrderId);
        if (! $order) {
            $this->dispatch('toast', message: 'Pesanan tidak ditemukan.', type: 'warning');

            return;
        }

        $this->splitBillOrderId = $order->id;
        $this->splitBillMode = 'equal';
        $this->splitBillParts = 2;
        $totalAmt = (float) $order->order_amount;
        $half1 = (int) floor($totalAmt / 2);
        $half2 = (int) ($totalAmt - $half1);
        $this->splitBillAmounts = [$half1, $half2];

        $this->splitBillItemAssignments = [];
        foreach ($order->orderItems as $item) {
            $this->splitBillItemAssignments[$item->id] = 0;
        }

        $this->showSplitBillModal = true;
    }

    public function closeSplitBillModal(): void
    {
        $this->showSplitBillModal = false;
        $this->splitBillOrderId = null;
    }

    public function setSplitBillParts(int $parts): void
    {
        $parts = max(2, min(10, $parts));
        $this->splitBillParts = $parts;

        $splitOrder = $this->splitBillOrder();
        if ($splitOrder !== null) {
            $totalAmt = (string) $splitOrder->order_amount;
            $partsStr = (string) $parts;
            $perPart = bcdiv($totalAmt, $partsStr, 0);
            $totalDivided = bcmul($perPart, (string) ($parts - 1), 0);
            $remainder = bcsub($totalAmt, $totalDivided, 0);

            $amounts = [];
            for ($i = 0; $i < $parts; $i++) {
                $amounts[] = (int) (($i === 0) ? $remainder : $perPart);
            }
            $this->splitBillAmounts = $amounts;
        }
    }

    public function addSplitBillAmountPart(): void
    {
        $this->splitBillAmounts[] = 0;
    }

    public function removeSplitBillAmountPart(int $index): void
    {
        if (count($this->splitBillAmounts) > 2) {
            unset($this->splitBillAmounts[$index]);
            $this->splitBillAmounts = array_values($this->splitBillAmounts);
        }
    }

    #[Computed]
    public function splitBillOrder(): ?Order
    {
        if (! $this->splitBillOrderId) {
            return null;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        return Order::with(['orderItems.product', 'table'])->where('tenant_id', $tenantId)->find($this->splitBillOrderId);
    }

    public function executeSplitBill(SplitBillService $splitService): void
    {
        if (! $this->splitBillOrderId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $order = Order::with('orderItems')->where('tenant_id', $tenantId)->findOrFail($this->splitBillOrderId);
        $userId = (int) auth()->id();

        try {
            if ($this->splitBillMode === 'item') {
                $splits = [
                    ['sub_order_label' => 'Sub-Order 1', 'items' => []],
                    ['sub_order_label' => 'Sub-Order 2', 'items' => []],
                ];

                foreach ($order->orderItems as $item) {
                    $assignedPart = (int) ($this->splitBillItemAssignments[$item->id] ?? 0);
                    $targetPart = ($assignedPart === 1) ? 1 : 0;
                    $splits[$targetPart]['items'][] = [
                        'order_item_id' => $item->id,
                        'quantity' => (int) $item->qty,
                    ];
                }

                if (empty($splits[0]['items']) || empty($splits[1]['items'])) {
                    $this->dispatch('toast', message: 'Setiap sub-order harus memiliki minimal 1 item pesanan.', type: 'warning');

                    return;
                }

                $subOrders = $splitService->splitByItem($order, $splits, $userId);
            } else {
                $amounts = array_map(fn ($a) => (int) round((float) $a), $this->splitBillAmounts);
                $sum = array_sum($amounts);
                $totalOrder = (int) $order->order_amount;

                if ($sum !== $totalOrder) {
                    $this->dispatch('toast', message: 'Total nominal pembagian (Rp '.number_format($sum, 0, ',', '.').') harus sama dengan total pesanan (Rp '.number_format($totalOrder, 0, ',', '.').').', type: 'warning');

                    return;
                }

                $subOrders = $splitService->splitByAmount($order, $amounts, $userId);
            }

            $count = count($subOrders);
            $this->closeSplitBillModal();
            $this->clearCart();
            $this->pendingOrderId = null;
            $this->pendingOrderCode = null;

            $this->dispatch('toast', message: "Tagihan #{$order->order_code} berhasil dipecah menjadi {$count} sub-order!", type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Gagal split bill: '.$e->getMessage(), type: 'destructive');
        }
    }

    public function closeModifierPicker(): void
    {
        $this->selectingProductId = null;
        $this->selectingVariantProductId = null;
        $this->pendingVariantId = null;
        $this->pendingUomId = null;
        $this->pendingModifierIds = [];
        $this->pendingQty = 1;
        $this->pendingNotes = null;
    }

    public function toggleModifier(int $groupId, int $modifierId, string $selectionType): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        $group = ModifierGroup::with('modifiers')->find($groupId);

        if ($selectionType === 'single') {
            // Hapus modifier lain yang berada dalam grup yang sama, lalu tambahkan yang baru
            $groupModifierIds = $group ? $group->modifiers->pluck('id')->toArray() : Modifier::where('modifier_group_id', $groupId)->pluck('id')->toArray();
            $this->pendingModifierIds = array_diff($this->pendingModifierIds, $groupModifierIds);
            $this->pendingModifierIds[] = $modifierId;
        } else {
            // Toggle multiple
            if (in_array($modifierId, $this->pendingModifierIds, true)) {
                $this->pendingModifierIds = array_diff($this->pendingModifierIds, [$modifierId]);
            } else {
                if ($group && $group->max_select !== null && $group->max_select > 0) {
                    $groupModifierIds = $group->modifiers->pluck('id')->toArray();
                    $selectedInGroup = count(array_intersect($this->pendingModifierIds, $groupModifierIds));
                    if ($selectedInGroup >= $group->max_select) {
                        $this->dispatch('toast', message: "Maksimal {$group->max_select} opsi untuk {$group->name}.", type: 'warning');

                        return;
                    }
                }
                $this->pendingModifierIds[] = $modifierId;
            }
        }
        $this->pendingModifierIds = array_values($this->pendingModifierIds); // reindex
    }

    public function confirmAddToCart(): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        if (! $this->selectingProductId) {
            return;
        }

        $this->pendingQty = max(1, (int) $this->pendingQty);

        $product = Product::with([
            'modifierGroups.modifiers',
            'variants' => fn ($q) => $q->where('is_active', true),
            'unitConversions',
        ])->findOrFail($this->selectingProductId);

        $storeId = app(TenantContext::class)->getStoreId();
        $resolver = app(CatalogPriceResolverService::class);
        if (! $resolver->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        // 1. Validasi Pemilihan Varian (jika produk memiliki varian aktif)
        $activeVariants = $product->variants->where('is_active', true);
        $selectedVariant = null;
        if ($activeVariants->isNotEmpty()) {
            if (! $this->pendingVariantId) {
                $this->addError('cart', "Pilih varian untuk produk {$product->product_name}.");

                return;
            }
            $selectedVariant = $activeVariants->firstWhere('id', $this->pendingVariantId);
            if (! $selectedVariant) {
                $this->addError('cart', "Varian yang dipilih tidak valid untuk produk {$product->product_name}.");

                return;
            }
        }

        // 2. Validasi Modifiers
        $selectedModifiers = collect();
        if (! empty($this->pendingModifierIds)) {
            $selectedModifiers = Modifier::whereIn('id', $this->pendingModifierIds)->get();
            if ($selectedModifiers->count() !== count($this->pendingModifierIds)) {
                $this->addError('cart', 'Invalid modifiers selected.');

                return;
            }
        }

        // 3. Validasi aturan modifier groups (is_required, min_select, max_select, single)
        foreach ($product->modifierGroups as $group) {
            $selectedInGroup = $selectedModifiers->where('modifier_group_id', $group->id)->count();

            if ($group->is_required && $selectedInGroup === 0) {
                $this->addError('cart', "Required option for {$group->name} has not been selected.");

                return;
            }

            if (! empty($group->min_select) && $selectedInGroup < $group->min_select) {
                $this->addError('cart', "Minimum {$group->min_select} option(s) required for {$group->name}.");

                return;
            }

            if ($group->selection_type === 'single' && $selectedInGroup > 1) {
                $this->addError('cart', "Only one option may be selected for {$group->name}.");

                return;
            }

            if (! empty($group->max_select) && $selectedInGroup > $group->max_select) {
                $this->addError('cart', "Maximum {$group->max_select} option(s) allowed for {$group->name}.");

                return;
            }
        }

        // 4. Hitung harga dan faktor UOM
        $basePrice = (float) ($selectedVariant !== null && $selectedVariant->price_override !== null
            ? $selectedVariant->price_override
            : $product->product_price);
        $uomFactor = 1.0;
        if ($this->pendingUomId && $this->pendingUomId !== $product->base_uom_id && $product->base_uom_id) {
            try {
                $tenantId = (int) app(TenantContext::class)->requireTenantId();
                $converted = app(\App\Modules\MasterData\Services\UnitConversionService::class)->convertBetweenUoms(
                    tenantId: $tenantId,
                    fromUomId: (int) $this->pendingUomId,
                    toUomId: (int) $product->base_uom_id,
                    quantity: 1,
                    productId: (int) $product->id,
                    scale: 6
                );
                $uomFactor = (float) $converted;
            } catch (\Throwable) {
                $conversion = $product->unitConversions->firstWhere('from_uom_id', $this->pendingUomId);
                if ($conversion) {
                    $uomFactor = (float) $conversion->conversion_factor;
                }
            }
        } elseif ($this->pendingUomId && $this->pendingUomId !== $product->base_uom_id) {
            $conversion = $product->unitConversions->firstWhere('from_uom_id', $this->pendingUomId);
            if ($conversion) {
                $uomFactor = (float) $conversion->conversion_factor;
            }
        }

        $effectiveBasePrice = (float) bcmul((string) $basePrice, (string) $uomFactor, 2);

        // 5. Cek apakah item identik sudah ada di keranjang untuk digabungkan
        $normalizedPendingMods = $this->pendingModifierIds;
        sort($normalizedPendingMods);
        $normalizedPendingUom = ($this->pendingUomId === $product->base_uom_id) ? null : $this->pendingUomId;

        foreach ($this->cart as $index => $item) {
            $itemMods = $item['modifier_ids'] ?? [];
            sort($itemMods);
            $itemUom = (($item['uom_id'] ?? null) === $product->base_uom_id) ? null : ($item['uom_id'] ?? null);
            if (
                $item['product_id'] === $this->selectingProductId &&
                ($item['product_variant_id'] ?? null) === $this->pendingVariantId &&
                $itemUom === $normalizedPendingUom &&
                $itemMods === $normalizedPendingMods &&
                ($item['notes'] ?? null) === $this->pendingNotes
            ) {
                $this->cart[$index]['qty'] += $this->pendingQty;
                $this->closeModifierPicker();
                $this->dispatch('pos-cart-state-changed', dirty: true);

                return;
            }
        }

        $cartItem = [
            'product_id' => $this->selectingProductId,
            'product_variant_id' => $this->pendingVariantId,
            'uom_id' => $this->pendingUomId,
            'qty' => $this->pendingQty,
            'modifier_ids' => $this->pendingModifierIds,
            'notes' => $this->pendingNotes,
        ];

        if ($uomFactor !== 1.0) {
            $cartItem['custom_price'] = $effectiveBasePrice;
        }

        $this->cart[] = $cartItem;
        $this->closeModifierPicker();
        $this->dispatch('pos-cart-state-changed', dirty: true);
    }

    public function addToCart(int $productId): void
    {
        $this->addToCartDirectly($productId);
    }

    public function addToCartDirectly(int $productId): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        $product = Product::with(['modifierGroups', 'variants', 'unitConversions'])->findOrFail($productId);
        $storeId = app(TenantContext::class)->getStoreId();
        $resolver = app(CatalogPriceResolverService::class);
        if (! $resolver->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        // Jika memiliki varian aktif, buka configurator modal
        $activeVariants = $product->variants->where('is_active', true);
        if ($activeVariants->isNotEmpty()) {
            $this->selectingVariantProductId = $productId;
            $this->openModifierPicker($productId);

            return;
        }

        // Jika memiliki modifier groups atau multi-UOM, buka configurator modal
        $hasModifiers = $product->modifierGroups->isNotEmpty();
        $hasUnitConversions = $product->unitConversions->isNotEmpty();
        if ($hasModifiers || $hasUnitConversions) {
            $this->openModifierPicker($productId);

            return;
        }

        // Fast-Path (Tanpa Modal): Tambahkan langsung ke keranjang dalam 1 klik
        foreach ($this->cart as $index => $item) {
            if (
                $item['product_id'] === $product->id &&
                empty($item['product_variant_id']) &&
                empty($item['uom_id']) &&
                empty($item['modifier_ids']) &&
                empty($item['notes'])
            ) {
                $this->cart[$index]['qty']++;
                $this->dispatch('pos-cart-state-changed', dirty: true);

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'uom_id' => null,
            'qty' => 1,
            'modifier_ids' => [],
            'notes' => null,
        ];
        $this->dispatch('pos-cart-state-changed', dirty: true);
    }

    public function removeFromCart(int $index): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    public function updateCartQty(int $index, int $delta): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        if (! isset($this->cart[$index])) {
            return;
        }

        $newQty = (int) ($this->cart[$index]['qty'] ?? 1) + $delta;
        if ($newQty <= 0) {
            $this->removeFromCart($index);

            return;
        }

        $this->cart[$index]['qty'] = $newQty;
    }

    public function clearCart(): void
    {
        if ($this->pendingOrderId !== null) {
            $this->cancelPendingOrderMode();
        }

        $this->cart = [];
        $this->dispatch('pos-cart-state-changed', dirty: false);
        $this->selectedDiscountId = null;
        $this->cashReceived = 0;
        $this->resetValidation();
    }

    // READ-ONLY preview -- panggil ulang method Service yang sama persis
    // dengan yang dipakai saat persist, supaya angka di layar TIDAK PERNAH
    // berbeda dari yang tersimpan ke DB nanti.
    #[Computed]
    public function cartLines()
    {
        $service = app(TransactionService::class);

        // OPTIMASI N+1: computed property ini dieksekusi ulang pada SETIAP interaksi
        // Livewire. Versi lama menjalankan Product::findOrFail() SATU KALI PER ITEM
        // keranjang pada SETIAP render -- kasir dengan 5 item bisa memicu puluhan query
        // hanya untuk preview keranjang. Sekarang semua produk relevan diambil dalam
        // SATU query batch, lengkap dengan modifierGroups eager-loaded.
        $productIds = collect($this->cart)->pluck('product_id')->unique()->values();
        $variantIds = collect($this->cart)->pluck('product_variant_id')->filter()->unique()->values();
        $uomIds = collect($this->cart)->pluck('uom_id')->filter()->unique()->values();
        $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');
        $uoms = UnitOfMeasure::whereIn('id', $uomIds)->get()->keyBy('id');

        return collect($this->cart)->map(function ($raw, $i) use ($service, $products, $variants, $uoms) {
            $product = $products->get($raw['product_id']);
            if (! $product) {
                return null;
            }

            $qty = max(1, (int) ($raw['qty'] ?? 1));
            $variantId = isset($raw['product_variant_id']) ? (int) $raw['product_variant_id'] : null;
            $uomId = isset($raw['uom_id']) ? (int) $raw['uom_id'] : null;
            $line = $service->resolveOrderItemLine(
                $product,
                $raw['modifier_ids'] ?? [],
                $qty,
                $raw['notes'] ?? null,
                $variantId,
                $this->customerId,
                'pos_cashier',
                isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                $uomId
            );

            if ($variantId && isset($variants[$variantId])) {
                $line['variant_name'] = $variants[$variantId]->variant_name;
                $line['product_name'] = "{$product->product_name} - {$variants[$variantId]->variant_name}";
            }

            if ($uomId && isset($uoms[$uomId])) {
                $line['uom_name'] = $uoms[$uomId]->name;
                $line['product_name'] .= " ({$uoms[$uomId]->code})";
            }

            return [...$line, 'index' => $i];
        })->filter()->values();
    }

    #[Computed]
    public function cartQtyByProduct(): array
    {
        $map = [];
        foreach ($this->cart as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 1);
            if ($productId > 0) {
                $map[$productId] = ($map[$productId] ?? 0) + $qty;
            }
        }

        return $map;
    }

    #[Computed]
    public function subtotal(): int
    {
        return $this->cartLines->sum('order_subtotal');
    }

    #[Computed]
    public function activeDiscounts()
    {
        return Discount::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->get();
    }

    #[Computed]
    public function selectedDiscount(): ?Discount
    {
        if (! $this->selectedDiscountId) {
            return null;
        }

        return Discount::find($this->selectedDiscountId);
    }

    public function updatedSelectedDiscountId(): void
    {
        if ($this->pendingOrderId !== null) {
            $this->selectedDiscountId = null;
            $this->addError('cart', 'Discounts cannot be modified on pending self-orders.');
        }
    }

    public function setItemDiscount(int $indexOrProductId, int $discountAmount): void
    {
        $discountAmount = max(0, $discountAmount);
        $this->itemDiscounts[$indexOrProductId] = $discountAmount;
        if (isset($this->cart[$indexOrProductId])) {
            $this->cart[$indexOrProductId]['item_discount_amount'] = $discountAmount;
        }
        foreach ($this->cart as $i => $item) {
            if (($item['product_id'] ?? null) === $indexOrProductId) {
                $this->cart[$i]['item_discount_amount'] = $discountAmount;
            }
        }
    }

    #[Computed]
    public function totalItemDiscount(): int
    {
        return (int) collect($this->cart)->sum(fn ($item) => (int) ($item['item_discount_amount'] ?? 0));
    }

    public function applyManualDiscount(string $pin = ''): void
    {
        if ($this->manualDiscountAmountInput !== null) {
            $this->manualDiscountAmount = (int) $this->manualDiscountAmountInput;
        }

        $this->validate([
            'manualDiscountAmount' => 'required|integer|min:1',
            'manualDiscountReason' => 'required|string|min:3|max:255',
        ]);

        $subtotal = (float) $this->subtotal();
        $amount = (float) $this->manualDiscountAmount;
        $percent = $subtotal > 0 ? (($amount / $subtotal) * 100) : 100.0;

        $approvalService = app(DiscountApprovalService::class);
        $needsApproval = $approvalService->evaluateThreshold($percent, $amount, $subtotal);

        if ($needsApproval) {
            $pinValidator = validator(
                ['pin' => $pin],
                ['pin' => 'required|string|min:4'],
                ['pin.required' => 'Supervisor PIN is required.', 'pin.min' => 'Supervisor PIN must be at least 4 characters.']
            );

            if ($pinValidator->fails()) {
                $this->addError('manualDiscountSupervisorPin', $pinValidator->errors()->first('pin'));

                return;
            }

            $storeId = (int) (app(TenantContext::class)->getStoreId() ?? Auth::user()->store_id ?? 0);

            try {
                $approval = $approvalService->createApproval(
                    requesterId: (int) Auth::id(),
                    pin: $pin,
                    action: Approval::ACTION_MANUAL_DISCOUNT,
                    amount: $amount,
                    reason: $this->manualDiscountReason,
                    storeId: $storeId
                );
                $this->manualDiscountApprovalId = (int) $approval->id;
            } catch (ValidationException $e) {
                $msg = $e->validator->errors()->first('pin') ?: $e->getMessage();
                $this->addError('manualDiscountSupervisorPin', $msg);
                $this->dispatch('toast', message: $msg, type: 'error');

                return;
            }
        } else {
            $this->manualDiscountApprovalId = null;
        }

        $this->appliedManualDiscount = (int) $this->manualDiscountAmount;
        $this->appliedManualDiscountReason = $this->manualDiscountReason;
        $this->isManualDiscountModalOpen = false;
        $this->dispatch('toast', message: 'Manual discount applied successfully.', type: 'success');
    }

    public function addPaymentLeg(): void
    {
        $this->paymentLegs[] = [
            'method' => $this->paymentMethod,
            'amount' => (int) $this->paymentTendered,
        ];
    }

    public function removeManualDiscount(): void
    {
        $this->appliedManualDiscount = 0;
        $this->appliedManualDiscountReason = '';
        $this->manualDiscountAmount = 0;
        $this->manualDiscountReason = '';
        $this->manualDiscountApprovalId = null;
        $this->dispatch('toast', message: 'Manual discount removed.', type: 'info');
    }

    public function parkCurrentCart(): void
    {
        if (empty($this->cart)) {
            $this->addError('cart', 'Cart is empty and cannot be parked.');

            return;
        }

        $user = Auth::user();
        $activeShift = Shift::where(function ($q) use ($user) {
            $q->where('active_cashier_id', $user->id)
                ->orWhere(function ($sub) use ($user) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                });
        })->where('status', 'open')->first();

        if (! $activeShift) {
            $this->addError('cart', 'POS terminal does not have an open register shift.');

            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        $cartState = [
            'items' => $this->cart,
            'order_type' => $this->orderType ?? 'dine_in',
            'table_id' => $this->tableId,
            'customer_id' => $this->customerId,
            'discount_id' => $this->selectedDiscountId,
            'notes' => $this->holdNotes ?: null,
        ];

        try {
            $storeId = $this->resolveActiveStoreId($activeShift->store_id ?? $this->activeStoreId, $tenantId);
            $deviceId = $this->resolveActiveDeviceId($this->deviceId, $activeShift->device_id, $storeId, $tenantId);
            $label = ! empty($this->holdLabel) ? $this->holdLabel : (! empty($this->holdReference) ? $this->holdReference : null);
            $parked = app(CartService::class)->parkCart(
                $tenantId,
                $storeId,
                $deviceId,
                $activeShift->id,
                (int) $user->id,
                $cartState,
                $label
            );

            $this->cart = [];
            $this->holdReference = '';
            $this->holdLabel = '';
            $this->holdNotes = '';
            $this->isParkModalOpen = false;
            $this->dispatch('pos-cart-state-changed', dirty: false);
            $this->dispatch('toast', message: "Order parked successfully (Ref: {$parked->hold_reference})", type: 'success');
        } catch (\Throwable $e) {
            $this->addError('cart', $e->getMessage());
        }
    }

    public function resumeCart(int $parkedCartId): void
    {
        $user = Auth::user();
        $activeShift = Shift::where(function ($q) use ($user) {
            $q->where('active_cashier_id', $user->id)
                ->orWhere(function ($sub) use ($user) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                });
        })->where('status', 'open')->first();

        if (! $activeShift) {
            $this->addError('cart', 'Register shift must be active to resume parked orders.');

            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        if ($parkedCartId <= 0) {
            $latestParked = ParkedCart::where('status', ParkedCart::STATUS_PARKED)->latest()->first();
            if ($latestParked) {
                $parkedCartId = (int) $latestParked->id;
            }
        }

        try {
            $storeId = $this->resolveActiveStoreId($activeShift->store_id ?? $this->activeStoreId, $tenantId);
            $deviceId = $this->resolveActiveDeviceId($this->deviceId, $activeShift->device_id, $storeId, $tenantId);
            $result = app(CartService::class)->resumeParkedCart(
                $parkedCartId,
                $tenantId,
                $storeId,
                $deviceId,
                $activeShift->id,
                (int) $user->id
            );

            $payload = $result['cart'];
            $items = $payload['items'] ?? $payload;

            $this->cart = array_values($items);
            if (isset($payload['order_type'])) {
                $this->orderType = $payload['order_type'];
            }
            if (isset($payload['table_id'])) {
                $this->tableId = $payload['table_id'];
            }
            if (isset($payload['customer_id'])) {
                $this->customerId = $payload['customer_id'];
            }
            if (isset($payload['discount_id'])) {
                $this->selectedDiscountId = $payload['discount_id'];
            }

            $this->resumeWarnings = $result['warnings'];
            $this->isParkedListOpen = false;
            $this->dispatch('pos-cart-state-changed', dirty: true);
            $this->dispatch('toast', message: 'Parked order resumed successfully.', type: 'success');
        } catch (\Throwable $e) {
            $this->addError('cart', $e->getMessage());
        }
    }

    public function discardHeldCart(int $parkedCartId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        try {
            $storeId = $this->resolveActiveStoreId($this->activeStoreId, $tenantId);
            app(CartService::class)->discardParkedCart($parkedCartId, $tenantId, $storeId, (int) Auth::id());
            $this->dispatch('toast', message: 'Parked order discarded.', type: 'info');
        } catch (\Throwable $e) {
            $this->addError('cart', $e->getMessage());
        }
    }

    #[Computed]
    public function parkedCarts()
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();
        if (! $tenantId || ! $storeId) {
            return collect();
        }

        return app(CartService::class)->getParkedCarts($tenantId, $storeId);
    }

    #[Computed]
    public function activeShift(): ?Shift
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return Shift::with('device')->where(function ($q) use ($user) {
            $q->where('active_cashier_id', $user->id)
                ->orWhere(function ($sub) use ($user) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                });
        })->whereIn('status', ['open', 'handover_pending', 'closing'])->first();
    }

    #[Computed]
    public function canCheckout(): bool
    {
        $shift = $this->activeShift();

        return $shift !== null
            && $shift->status === 'open'
            && count($this->cart) > 0;
    }

    #[Computed]
    public function discountAmount(): int
    {
        $subtotal = $this->subtotal;
        $voucherDiscount = 0;
        $discount = $this->selectedDiscount;

        if ($discount && $discount->is_active) {
            if ($subtotal >= $discount->min_purchase_amount && $discount->hasAvailableQuota()) {
                if ($discount->type === 'percentage') {
                    $calc = (int) round($subtotal * ($discount->value / 100));
                    if ($discount->max_discount_amount) {
                        $calc = min($calc, $discount->max_discount_amount);
                    }
                    $voucherDiscount = $calc;
                } else {
                    $voucherDiscount = min($subtotal, (int) $discount->value);
                }
            }
        }

        $manualDiscount = (int) $this->appliedManualDiscount;

        // Validasi stackable: jika voucher tidak stackable dan ada manual discount
        if ($voucherDiscount > 0 && $manualDiscount > 0 && $discount && ! $discount->is_stackable) {
            return min($subtotal, max($voucherDiscount, $manualDiscount) + $this->totalItemDiscount());
        }

        $totalDisc = $voucherDiscount + $manualDiscount + $this->totalItemDiscount();

        return min($subtotal, $totalDisc);
    }

    #[Computed]
    public function orderTotals(): array
    {
        return app(TransactionService::class)->calculateOrderTotals($this->subtotal, $this->discountAmount);
    }

    #[Computed]
    public function taxAmount(): int
    {
        if ($this->pendingOrderId) {
            $order = Order::find($this->pendingOrderId);
            if ($order) {
                return (int) $order->tax_amount;
            }
        }

        return $this->orderTotals()['tax_amount'];
    }

    #[Computed]
    public function totalAmount(): int
    {
        if ($this->pendingOrderId) {
            $order = Order::find($this->pendingOrderId);
            if ($order) {
                return (int) $order->order_amount;
            }
        }

        return $this->orderTotals()['total_amount'];
    }

    #[Computed]
    public function taxRatePercent(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $rate = $tenantId !== null
            ? (float) Setting::get('tax_rate', config('pos.tax_rate', 0.11))
            : (float) config('pos.tax_rate', 0.11);

        return rtrim(rtrim(number_format($rate * 100, 1), '0'), '.');
    }

    #[Computed]
    public function changeAmount(): int
    {
        // HANYA relevan untuk skenario Tarik Pesanan (pembayaran tunggal).
        return max(0, (int) $this->cashReceived - $this->totalAmount);
    }

    /**
     * Fast Cash Tender (1-Tap): Menyelesaikan pesanan tunai instan tanpa membuka modal.
     */
    public function fastCashPay(int $amountTendered = 0): void
    {
        if (! $this->canCheckout() || count($this->cart) === 0) {
            return;
        }

        if (! $this->orderType) {
            $this->orderType = 'takeaway';
        }

        $totalDue = $this->totalAmount();
        $tendered = $amountTendered > 0 ? max($amountTendered, $totalDue) : $totalDue;
        $change = max(0, $tendered - $totalDue);

        $legs = [
            [
                'method' => 'cash',
                'amount' => $totalDue,
                'amount_tendered' => $tendered,
                'change_amount' => $change,
            ],
        ];

        $this->submitOrder($legs, (string) Str::uuid());
    }

    /**
     * Daftar rekening bank operasional aktif cabang toko untuk transfer.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    #[Computed]
    public function storeBankAccounts(): \Illuminate\Support\Collection
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId() ?? $this->activeStoreId;

        if (! $tenantId) {
            return collect();
        }

        $query = \Illuminate\Support\Facades\DB::table('cash_bank_accounts')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('account_type', 'BANK_ACCOUNT');

        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            });
        }

        /** @var \Illuminate\Support\Collection<int, \stdClass> $accounts */
        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $fallback = new \stdClass();
            $fallback->id = 0;
            $fallback->bank_name = 'Bank Central Asia (BCA)';
            $fallback->account_name = 'Rekening Operasional Toko';
            $fallback->bank_account_number = '8010 9928 1120';
            $fallback->bank_account_holder = 'Apeiron System Coffee';

            return collect([$fallback]);
        }

        return $accounts;
    }

    /**
     * Daftar terminal EDC aktif cabang toko untuk transaksi kartu.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    #[Computed]
    public function storeEdcTerminals(): \Illuminate\Support\Collection
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId() ?? $this->activeStoreId;

        if (! $tenantId) {
            return collect();
        }

        $query = \Illuminate\Support\Facades\DB::table('devices')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('device_type', 'pos_terminal');

        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            });
        }

        /** @var \Illuminate\Support\Collection<int, \stdClass> $terminals */
        $terminals = $query->get();

        if ($terminals->isEmpty()) {
            $t1 = new \stdClass();
            $t1->id = 1;
            $t1->device_name = 'Terminal BCA #01';
            $t1->device_code = 'BCA-EDC-01';

            $t2 = new \stdClass();
            $t2->id = 2;
            $t2->device_name = 'Terminal Mandiri #02';
            $t2->device_code = 'MDR-EDC-02';

            return collect([$t1, $t2]);
        }

        return $terminals;
    }

    /**
     * Menerima $legs dari Alpine (Split Payment modal) untuk
     * skenario Walk-in Baru. Skenario Tarik Pesanan tetap dilayani via
     * $this->paymentMethod/$this->cashReceived seperti sebelumnya --
     * $legs diabaikan sepenuhnya di cabang itu (lihat SYNC ALERT Node 1).
     *
     * @param  array<int, array{method: string, amount: int, amount_tendered?: int|null, change_amount?: int|null, card_number?: string|null, reference_number?: string|null, pin?: string|null, use_midtrans_gateway?: bool|null}>  $legs
     */
    public function submitOrder(array $legs = [], ?string $idempotencyKey = null, bool $useMidtransGateway = false)
    {
        $this->authorize('create', Order::class);

        $idempotencyKey = $idempotencyKey ?? $this->idempotencyKey;

        $rules = [
            'orderType' => ['required', 'in:dine_in,takeaway'],
            'cart' => ['required', 'array', 'min:1'],
        ];

        if ($this->pendingOrderId) {
            $rules['cashReceived'] = ['required', 'numeric', 'min:'.$this->totalAmount];
        }

        // Stale branch state guard
        $currentStoreId = app(TenantContext::class)->getStoreId();
        if ($this->activeStoreId !== null && $currentStoreId !== null && (int) $this->activeStoreId !== (int) $currentStoreId) {
            $this->addError('cart', 'Active branch has changed in your session. Please refresh the page before proceeding.');

            return;
        }

        if (! $this->isTablesEnabled() || $this->orderType !== 'dine_in') {
            $this->tableId = null;
        }

        if ($this->customerId) {
            Customer::findOrFail($this->customerId);
        }
        if ($this->selectedDiscountId) {
            Discount::findOrFail($this->selectedDiscountId);
        }
        if ($this->tableId) {
            Table::where('is_active', true)->findOrFail($this->tableId);
        }

        // Jika bukan pending order, validasi meja diperlukan untuk dine_in HANYA jika fitur meja aktif di cabang ini
        if (! $this->pendingOrderId && $this->orderType === 'dine_in' && $this->isTablesEnabled()) {
            $rules['tableId'] = ['required'];
        }

        $this->validate($rules);

        // Idempotency guard -- pola direplikasi persis dari
        // TransactionController::store() agar kasir yang panik menekan
        // tombol berkali-kali tidak menghasilkan order duplikat.
        if ($idempotencyKey && Order::where('idempotency_key', $idempotencyKey)->exists()) {
            return redirect()->route(
                'transaction.receipt',
                Order::where('idempotency_key', $idempotencyKey)->value('order_code')
            );
        }

        $lock = null;
        if ($idempotencyKey) {
            $lock = Cache::lock('order_idempotency_'.$idempotencyKey, 10);
            if (! $lock->get()) {
                $this->addError('cart', 'A similar transaction is currently processing, please try again.');

                return;
            }
        }

        try {
            DB::beginTransaction();

            $transactionService = app(TransactionService::class);

            // Zero-Trust shift guard, dipusatkan lewat Service
            // agar identik dengan alur TransactionController::store().
            $shiftId = $transactionService->resolveOpenShiftOrFail((int) Auth::id());

            // Normalisasi payment legs untuk KEDUA skenario (Walk-in & Pending)
            $normalizedLegs = [];
            $isMidtransGatewayOrder = $useMidtransGateway;

            if ($this->pendingOrderId && empty($legs)) {
                // Skenario Tarik Pesanan via form tunai langsung di kasir
                if ((int) $this->cashReceived < (int) $this->totalAmount) {
                    throw ValidationException::withMessages([
                        'cart' => 'Cash received is less than total amount due.',
                    ]);
                }
                $tendered = (int) $this->cashReceived;
                $allocated = (int) $this->totalAmount;
                $normalizedLegs[] = [
                    'method' => $this->paymentMethod,
                    'amount' => $allocated,
                    'amount_tendered' => $tendered,
                    'change_amount' => max(0, $tendered - $allocated),
                ];
            } else {
                foreach ($legs as $leg) {
                    $method = PaymentMethod::tryFrom((string) $leg['method']);
                    $amount = (int) $leg['amount'];

                    if (! $method || $amount <= 0) {
                        throw ValidationException::withMessages([
                            'cart' => 'One of the payment methods is invalid.',
                        ]);
                    }

                    if (! empty($leg['use_midtrans_gateway'])) {
                        $isMidtransGatewayOrder = true;
                    }

                    $tendered = isset($leg['amount_tendered'])
                        ? (int) $leg['amount_tendered']
                        : ($method === PaymentMethod::Cash && (int) $this->cashReceived >= $amount ? (int) $this->cashReceived : $amount);
                    $changeAmount = isset($leg['change_amount'])
                        ? (int) $leg['change_amount']
                        : ($method === PaymentMethod::Cash ? max(0, $tendered - $amount) : 0);

                    $normalizedLegs[] = [
                        'method' => $method->value,
                        'amount' => $amount,
                        'amount_tendered' => $tendered,
                        'change_amount' => $changeAmount,
                        'card_number' => $leg['card_number'] ?? $leg['reference_number'] ?? null,
                        'reference_number' => $leg['reference_number'] ?? $leg['card_number'] ?? null,
                        'pin' => $leg['pin'] ?? null,
                    ];
                }
            }

            if (empty($normalizedLegs)) {
                throw ValidationException::withMessages([
                    'cart' => 'At least one payment method is required before processing the transaction.',
                ]);
            }

            $sumLegs = array_sum(array_column($normalizedLegs, 'amount'));
            if ($sumLegs !== (int) $this->totalAmount()) {
                throw ValidationException::withMessages([
                    'cart' => 'Total payments (Rp'.number_format($sumLegs, 0, ',', '.').') do not match the total due (Rp'.number_format($this->totalAmount(), 0, ',', '.').').',
                ]);
            }

            if ($this->pendingOrderId) {
                // Skenario 2: Membayar Pesanan Self-Order yang tertunda.
                // Sekarang menggunakan TransactionService untuk insert ledger payments & loyalty.
                $tenantId = app(TenantContext::class)->requireTenantId();
                $storeId = app(TenantContext::class)->getStoreId();
                $orderQuery = Order::where('tenant_id', $tenantId);
                if ($storeId) {
                    $orderQuery->where('store_id', $storeId);
                }
                $order = $orderQuery->findOrFail($this->pendingOrderId);
                $transactionService->payPendingOrder(
                    $order,
                    $normalizedLegs,
                    (int) Auth::id(),
                    $shiftId,
                    $this->deviceId
                );
                $isMidtransGatewayOrder = false;
            } else {
                // Skenario 1: Pesanan Walk-in Baru + Split Payment / Midtrans Gateway + Loyalti.

                // Batch-load produk (pola sama dengan cartLines() di atas) agar
                // resolveOrderItemLine() tidak memicu N+1 saat membangun payload.
                $productIds = collect($this->cart)->pluck('product_id')->unique()->values();
                $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');

                $items = [];
                foreach ($this->cart as $raw) {
                    $product = $products->get($raw['product_id']) ?? Product::findOrFail($raw['product_id']);
                    $line = $transactionService->resolveOrderItemLine(
                        $product,
                        $raw['modifier_ids'] ?? [],
                        max(1, (int) ($raw['qty'] ?? 1)),
                        $raw['notes'] ?? null,
                        $raw['product_variant_id'] ?? null,
                        $this->customerId,
                        'pos_cashier',
                        isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                        isset($raw['uom_id']) ? (int) $raw['uom_id'] : null
                    );

                    $items[] = [
                        'product_id' => $line['product_id'],
                        'product_variant_id' => $raw['product_variant_id'] ?? null,
                        'uom_id' => isset($raw['uom_id']) ? (int) $raw['uom_id'] : null,
                        'custom_price' => isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                        'quantity' => $line['qty'],
                        'extra_price' => $line['order_price'] - $product->product_price,
                        'options' => $line['options'],
                        'notes' => $line['notes'] ?? null,
                        'item_discount_amount' => (int) ($raw['item_discount_amount'] ?? 0),
                    ];
                }

                $canUseMidtransSingleLeg = $isMidtransGatewayOrder
                    && count($normalizedLegs) === 1
                    && in_array($normalizedLegs[0]['method'], ['qris', 'ewallet'], true);

                $txPayload = [
                    'items' => $items,
                    'customer_id' => $this->customerId,
                    'table_id' => $this->tableId,
                    'order_type' => $this->orderType,
                    'origin_channel' => 'pos_cashier',
                    'device_id' => $this->deviceId,
                    'shift_id' => $shiftId,
                    'discount_id' => $this->selectedDiscountId,
                    'manual_discount_amount' => (int) $this->appliedManualDiscount,
                    'approval_id' => $this->manualDiscountApprovalId,
                    'cart_uuid' => $this->voucherCode ? $this->idempotencyKey : null,
                    'idempotency_key' => $idempotencyKey,
                ];

                if ($canUseMidtransSingleLeg) {
                    $txPayload['payment_method'] = $normalizedLegs[0]['method'];
                    $txPayload['use_midtrans_gateway'] = true;
                } else {
                    $txPayload['payments'] = $normalizedLegs;
                    $isMidtransGatewayOrder = false;
                }

                $order = $transactionService->createTransaction($txPayload, (int) Auth::id());
            }

            DB::commit();

            if ($isMidtransGatewayOrder) {
                $order->refresh();
                $this->dispatch(
                    'midtrans-payment-pending',
                    orderCode: $order->order_code,
                    snapToken: $order->snap_token,
                    totalAmount: (int) $order->order_amount,
                    syncUrl: route('api.order.sync-status', ['order_number' => $order->order_code])
                );
            } else {
                $this->dispatch('transaction-success', orderCode: $order->order_code, isCash: collect($normalizedLegs)->contains('method', 'cash'));
            }

            // Reset server-side cart & state untuk transaksi berikutnya
            $this->cart = [];
            $this->dispatch('pos-cart-state-changed', dirty: false);
            $this->orderType = null;
            $this->tableId = null;
            $this->customerId = null;
            $this->customerName = null;
            $this->selectedDiscountId = null;
            $this->appliedManualDiscount = 0;
            $this->appliedManualDiscountReason = '';
            $this->manualDiscountApprovalId = null;
            $this->voucherCode = null;
            $this->voucherInput = '';
            $this->voucherError = null;
            $this->resumeWarnings = [];
            $this->pendingOrderId = null;
            $this->pendingOrderCode = null;
            $this->cashReceived = 0;
            $this->idempotencyKey = (string) Str::uuid();

            return;

        } catch (\Exception $e) {
            DB::rollBack();
            if ($e instanceof ValidationException) {
                $this->addError('cart', collect($e->errors())->flatten()->first());
            } elseif ($e instanceof \DomainException) {
                $this->addError('cart', $e->getMessage());
            } else {
                Log::error('CreateOrder submitOrder error: '.$e->getMessage(), ['exception' => $e]);
                $this->addError('cart', 'A system error occurred while processing the transaction. Please try again.');
            }

            return;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Actively verify and synchronize a pending Midtrans order from the Cashier POS modal.
     */
    public function verifyMidtransPayment(string $orderCode): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $query = Order::where('tenant_id', $tenantId)->where('order_code', $orderCode);
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $order = $query->first();
        if (! $order) {
            $this->dispatch('toast', message: 'Order not found.', type: 'error');

            return;
        }

        if ($order->order_status === \App\Enums\OrderStatus::Pending) {
            try {
                $order = app(TransactionService::class)->syncOrderWithMidtrans($order);
            } catch (\Throwable $e) {
                Log::warning('verifyMidtransPayment sync failed: '.$e->getMessage(), ['order_code' => $orderCode]);
            }
        }

        $order->refresh();

        if ($order->order_status === \App\Enums\OrderStatus::Paid) {
            $this->cart = [];
            $this->customerId = null;
            $this->customerName = null;
            $this->selectedDiscountId = null;
            $this->dispatch('pos-cart-state-changed', dirty: false);
            $this->dispatch('transaction-success', orderCode: $order->order_code, isCash: false);
            $this->dispatch('toast', message: "Payment confirmed for {$order->order_code}.", type: 'success');

            return;
        }

        if ($order->order_status->isFinal()) {
            $this->dispatch('midtrans-payment-failed', orderCode: $order->order_code, status: $order->order_status->value);
            $this->dispatch('toast', message: "Payment status is {$order->order_status->label()} for {$order->order_code}.", type: 'error');

            return;
        }

        $this->dispatch('midtrans-payment-still-pending', orderCode: $order->order_code);
    }

    #[Computed]
    public function configuratorTotalPrice(): float
    {
        if (! $this->selectingProductId) {
            return 0.0;
        }

        $product = Product::with([
            'variants' => fn ($q) => $q->where('is_active', true),
            'unitConversions',
        ])->find($this->selectingProductId);

        if (! $product) {
            return 0.0;
        }

        $basePrice = (float) $product->product_price;
        if ($this->pendingVariantId) {
            $variant = $product->variants->firstWhere('id', $this->pendingVariantId);
            if ($variant && $variant->price_override !== null) {
                $basePrice = (float) $variant->price_override;
            }
        }

        $uomFactor = 1.0;
        if ($this->pendingUomId && $this->pendingUomId !== $product->base_uom_id && $product->base_uom_id) {
            try {
                $tenantId = (int) app(TenantContext::class)->requireTenantId();
                $converted = app(\App\Modules\MasterData\Services\UnitConversionService::class)->convertBetweenUoms(
                    tenantId: $tenantId,
                    fromUomId: (int) $this->pendingUomId,
                    toUomId: (int) $product->base_uom_id,
                    quantity: 1,
                    productId: (int) $product->id,
                    scale: 6
                );
                $uomFactor = (float) $converted;
            } catch (\Throwable) {
                $conversion = $product->unitConversions->firstWhere('from_uom_id', $this->pendingUomId);
                if ($conversion) {
                    $uomFactor = (float) $conversion->conversion_factor;
                }
            }
        } elseif ($this->pendingUomId && $this->pendingUomId !== $product->base_uom_id) {
            $conversion = $product->unitConversions->firstWhere('from_uom_id', $this->pendingUomId);
            if ($conversion) {
                $uomFactor = (float) $conversion->conversion_factor;
            }
        }

        $effectiveBase = (float) bcmul((string) $basePrice, (string) $uomFactor, 2);

        $extraPrice = 0.0;
        if (! empty($this->pendingModifierIds)) {
            $extraPrice = (float) Modifier::whereIn('id', $this->pendingModifierIds)->sum('extra_price');
        }

        $unitPrice = (float) bcadd((string) $effectiveBase, (string) $extraPrice, 2);
        $qty = max(1, $this->pendingQty);

        return (float) bcmul((string) $unitPrice, (string) $qty, 2);
    }

    #[Computed]
    public function formattedModalTotal(): string
    {
        return 'Rp '.number_format($this->configuratorTotalPrice(), 0, ',', '.');
    }

    #[Computed]
    public function isConfiguratorValid(): bool
    {
        if (! $this->selectingProductId) {
            return false;
        }

        $product = Product::with([
            'modifierGroups',
            'variants' => fn ($q) => $q->where('is_active', true),
        ])->find($this->selectingProductId);

        if (! $product) {
            return false;
        }

        if ($product->variants->isNotEmpty() && ! $this->pendingVariantId) {
            return false;
        }

        $requiredGroups = $product->modifierGroups->where('is_required', true);
        if ($requiredGroups->isNotEmpty()) {
            $selectedMods = Modifier::whereIn('id', $this->pendingModifierIds)->get();
            $selectedGroupIds = $selectedMods->pluck('modifier_group_id')->unique();
            foreach ($requiredGroups as $group) {
                if (! $selectedGroupIds->contains($group->id)) {
                    return false;
                }
                $minSelect = $group->min_select ?? 1;
                if ($selectedMods->where('modifier_group_id', $group->id)->count() < $minSelect) {
                    return false;
                }
            }
        }

        return true;
    }

    public function render()
    {
        // Resolve selectingProduct for the modal if present
        $selectingProduct = null;
        $variantStocks = [];
        if ($this->selectingProductId) {
            $selectingProduct = Product::with([
                'modifierGroups.modifiers',
                'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues'),
                'unitConversions.fromUom',
                'unitConversions.toUom',
                'baseUom',
                'ingredients',
                'category',
            ])->find($this->selectingProductId);

            if ($selectingProduct && $selectingProduct->variants->isNotEmpty()) {
                $tenantId = app(TenantContext::class)->getTenantId();
                $storeId = app(TenantContext::class)->getStoreId();

                if ($selectingProduct->track_inventory) {
                    $parentStock = DB::table('product_stock_balances')
                        ->where('tenant_id', $tenantId)
                        ->where('store_id', $storeId)
                        ->where('product_id', $selectingProduct->id)
                        ->value('quantity') ?? ($selectingProduct->stock ?? 0);

                    $hasVariantMovements = \Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'variant_id');
                    foreach ($selectingProduct->variants as $v) {
                        if ($hasVariantMovements) {
                            $movementCount = DB::table('stock_movements')
                                ->where('tenant_id', $tenantId)
                                ->where('store_id', $storeId)
                                ->where('variant_id', $v->id)
                                ->count();
                            if ($movementCount > 0) {
                                $vQty = DB::table('stock_movements')
                                    ->where('tenant_id', $tenantId)
                                    ->where('store_id', $storeId)
                                    ->where('variant_id', $v->id)
                                    ->sum('quantity');
                                $variantStocks[$v->id] = (float) $vQty;
                                continue;
                            }
                        }
                        $variantStocks[$v->id] = (float) $parentStock;
                    }
                } else {
                    foreach ($selectingProduct->variants as $v) {
                        $variantStocks[$v->id] = 999999;
                    }
                }
            }
        }

        $selectingVariantProduct = null;
        if ($this->selectingVariantProductId) {
            $selectingVariantProduct = $selectingProduct ?? Product::with(['variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues')])
                ->find($this->selectingVariantProductId);
        }

        return view('livewire.kasir.create-order', compact('selectingProduct', 'selectingVariantProduct', 'variantStocks'));
    }
}
