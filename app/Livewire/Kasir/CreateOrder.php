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
use App\Models\Order;
use App\Models\ParkedCart;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Table;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Services\VoucherService;
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

    // state modal pemilihan modifier
    #[Locked]
    public ?int $selectingProductId = null;

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

        $product = Product::findOrFail($productId);
        $storeId = app(TenantContext::class)->getStoreId();
        $resolver = app(CatalogPriceResolverService::class);
        if (! $resolver->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        $this->selectingProductId = $product->id;
        $this->pendingModifierIds = [];

        // Pre-select default modifiers
        $product = Product::with('modifierGroups.modifiers')->find($productId);
        if ($product) {
            foreach ($product->modifierGroups as $group) {
                foreach ($group->modifiers as $mod) {
                    if ($mod->is_default) {
                        $this->pendingModifierIds[] = $mod->id;
                        if ($group->selection_type === 'single') {
                            break; // only select the first default for single selection
                        }
                    }
                }
            }
        }

        $this->pendingQty = 1;
        $this->pendingNotes = null;
    }

    public function scanBarcode(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $product = Product::where('tenant_id', $tenantId)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                    ->orWhere('product_code', $code);
            })
            ->where('is_active', true)
            ->first();

        if ($product) {
            if ($product->modifierGroups()->count() > 0) {
                $this->openModifierPicker($product->id);
            } else {
                $this->addToCartDirectly($product->id);
                $this->dispatch('toast', message: "Added: {$product->product_name}", type: 'success');
            }
        } else {
            $this->dispatch('toast', message: "Barcode not found: {$code}", type: 'warning');
        }
    }

    public function closeModifierPicker(): void
    {
        $this->selectingProductId = null;
    }

    public function toggleModifier(int $groupId, int $modifierId, string $selectionType): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        if ($selectionType === 'single') {
            // Hapus modifier lain yang berada dalam grup yang sama, lalu tambahkan yang baru
            $groupModifierIds = Modifier::where('modifier_group_id', $groupId)->pluck('id')->toArray();
            $this->pendingModifierIds = array_diff($this->pendingModifierIds, $groupModifierIds);
            $this->pendingModifierIds[] = $modifierId;
        } else {
            // Toggle multiple
            if (in_array($modifierId, $this->pendingModifierIds)) {
                $this->pendingModifierIds = array_diff($this->pendingModifierIds, [$modifierId]);
            } else {
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

        $product = Product::with('modifierGroups')->findOrFail($this->selectingProductId);
        $storeId = app(TenantContext::class)->getStoreId();
        $resolver = app(CatalogPriceResolverService::class);
        if (! $resolver->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        if (! empty($this->pendingModifierIds)) {
            Modifier::whereIn('id', $this->pendingModifierIds)->get()->each(function ($mod) {
                // Ensure all modifiers belong to the current tenant via the global scope automatically.
            });
            $validCount = Modifier::whereIn('id', $this->pendingModifierIds)->count();
            if ($validCount !== count($this->pendingModifierIds)) {
                $this->addError('cart', 'Invalid modifiers selected.');

                return;
            }
        }

        // Validasi pemenuhan grup varian yang wajib (is_required = true)
        $requiredGroups = $product->modifierGroups->where('is_required', true);
        if ($requiredGroups->isNotEmpty()) {
            $selectedModifiers = Modifier::whereIn('id', $this->pendingModifierIds)->get();
            $selectedGroupIds = $selectedModifiers->pluck('modifier_group_id')->unique();
            foreach ($requiredGroups as $group) {
                if (! $selectedGroupIds->contains($group->id)) {
                    $this->addError('cart', "Required option for {$group->name} has not been selected.");

                    return;
                }
            }
        }

        // Try to find an identical item to group with
        foreach ($this->cart as $index => $item) {
            if (
                $item['product_id'] === $this->selectingProductId &&
                $item['modifier_ids'] === $this->pendingModifierIds &&
                $item['notes'] === $this->pendingNotes
            ) {
                $this->cart[$index]['qty'] += $this->pendingQty;
                $this->selectingProductId = null;

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $this->selectingProductId,
            'qty' => $this->pendingQty,
            'modifier_ids' => $this->pendingModifierIds,
            'notes' => $this->pendingNotes,
        ];
        $this->selectingProductId = null;
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

        $product = Product::with('modifierGroups')->findOrFail($productId);
        $storeId = app(TenantContext::class)->getStoreId();
        $resolver = app(CatalogPriceResolverService::class);
        if (! $resolver->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }
        $hasRequiredModifiers = $product->modifierGroups->where('is_required', true)->isNotEmpty();
        if ($hasRequiredModifiers) {
            $this->openModifierPicker($productId);

            return;
        }

        // Group with identical item if exists
        foreach ($this->cart as $index => $item) {
            if (
                $item['product_id'] === $product->id &&
                empty($item['modifier_ids']) &&
                empty($item['notes'])
            ) {
                $this->cart[$index]['qty']++;

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $product->id,
            'qty' => 1,
            'modifier_ids' => [],
            'notes' => null,
        ];
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
        $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');

        return collect($this->cart)->map(function ($raw, $i) use ($service, $products) {
            $product = $products->get($raw['product_id']);
            if (! $product) {
                return null;
            }

            $qty = max(1, (int) ($raw['qty'] ?? 1));
            $line = $service->resolveOrderItemLine(
                $product,
                $raw['modifier_ids'] ?? [],
                $qty,
                $raw['notes'] ?? null,
                $raw['product_variant_id'] ?? null,
                $this->customerId,
                'pos_cashier'
            );

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
                        'pos_cashier'
                    );

                    $items[] = [
                        'product_id' => $line['product_id'],
                        'product_variant_id' => $raw['product_variant_id'] ?? null,
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

    public function render()
    {
        // Resolve selectingProduct for the modal if present
        $selectingProduct = null;
        if ($this->selectingProductId) {
            $selectingProduct = Product::with('modifierGroups.modifiers')->find($this->selectingProductId);
        }

        return view('livewire.kasir.create-order', compact('selectingProduct'));
    }
}
