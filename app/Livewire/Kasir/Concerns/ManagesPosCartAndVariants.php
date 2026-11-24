<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Modifier;
use App\Modules\Inventory\Models\ModifierGroup;
use App\Modules\Pricing\Services\CatalogPriceResolverService;
use App\Modules\Sales\Application\Services\PosCartCoordinatorService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Manages POS cashier cart manipulation, product configurator modal,
 * barcode scanning, and multi-variant / modifier selections (ADR-108).
 */
trait ManagesPosCartAndVariants
{
    use ManagesPosVariantPicker;

    public array $cart = [];

    #[Locked]
    public ?int $selectingProductId = null;

    public array $pendingModifierIds = [];

    public int $pendingQty = 1;

    public ?string $pendingNotes = null;

    public function getVariantStocks(Product $product): array
    {
        return app(PosCartCoordinatorService::class)->getVariantStocks($product, app(TenantContext::class)->getStoreId());
    }

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
        if (! app(CatalogPriceResolverService::class)->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        $this->selectingProductId = $product->id;
        $activeVariants = $product->variants->where('is_active', true);
        $this->selectingVariantProductId = $activeVariants->isNotEmpty() ? $product->id : null;

        $variantStocks = $this->getVariantStocks($product);
        $defaultVariant = $activeVariants->first(fn ($v) => ! $product->track_inventory || ($variantStocks[$v->id] ?? 0) > 0) ?? $activeVariants->first();

        if ($this->pendingVariantId && ! $activeVariants->contains('id', $this->pendingVariantId)) {
            $this->pendingVariantId = null;
        }

        $this->pendingVariantId ??= $defaultVariant?->id;
        $this->pendingUomId = $product->base_uom_id ?? null;
        $this->pendingModifierIds = [];
        $this->pendingQty = 1;
        $this->pendingNotes = null;

        foreach ($product->modifierGroups as $group) {
            foreach ($group->modifiers as $mod) {
                if ($mod->is_default && ($mod->is_active ?? true)) {
                    $this->pendingModifierIds[] = $mod->id;
                    if ($group->selection_type === 'single') {
                        break;
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

        $storeId = app(TenantContext::class)->getStoreId();
        $res = app(PosCartCoordinatorService::class)->resolveBarcode($code, $storeId);

        if ($res['action'] === 'error') {
            $this->dispatch('toast', message: $res['error'] ?? 'Barcode tidak valid.', type: 'warning');

            return;
        }

        if ($res['action'] === 'variant' && isset($res['variant'])) {
            $this->addVariantToCart($res['variant']);
            $this->dispatch('toast', message: $res['message'] ?? 'Produk ditambahkan.', type: 'success');
        } elseif ($res['action'] === 'scale' && isset($res['scaleProduct'], $res['scaleData'])) {
            $this->addScaleProductToCart($res['scaleProduct'], $res['scaleData']);
            $this->dispatch('toast', message: $res['message'] ?? 'Produk timbangan ditambahkan.', type: 'success');
        } elseif ($res['action'] === 'picker' && isset($res['product'])) {
            $this->openModifierPicker($res['product']->id);
        } elseif ($res['action'] === 'direct' && isset($res['product'])) {
            $this->addToCartDirectly($res['product']->id);
            $this->dispatch('toast', message: $res['message'] ?? 'Produk ditambahkan.', type: 'success');
        }
    }

    /**
     * Penanganan terpadu input pencarian / scanner saat tombol Enter ditekan (BAR-01).
     * Jika query cocok persis dengan barcode/SKU, tambahkan ke keranjang dan bersihkan kolom pencarian.
     * Jika tidak cocok, pertahankan teks pencarian untuk memfilter katalog produk di grid.
     */
    public function handleSearchEnter(string $query): void
    {
        $code = trim($query);
        if ($code === '') {
            return;
        }

        $storeId = app(TenantContext::class)->getStoreId();
        $res = app(PosCartCoordinatorService::class)->resolveBarcode($code, $storeId);

        if ($res['action'] !== 'error') {
            $this->scanBarcode($code);
            $this->search = '';
        } else {
            $this->search = $code;
        }
    }

    /**
     * @param  array{is_scale: bool, prefix: string, item_code: string, weight_grams: int, weight_kg: numeric-string, embedded_price: int, check_digit: int}  $scaleData
     */
    public function addScaleProductToCart(Product $product, array $scaleData): void
    {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        if ($scaleData['prefix'] === '02') {
            $linePrice = (float) bcmul((string) $scaleData['weight_kg'], (string) $product->product_price, 2);
            $notes = "Timbangan: {$scaleData['weight_kg']} kg ({$scaleData['weight_grams']}g)";
        } else {
            $linePrice = (float) $scaleData['embedded_price'];
            $notes = 'Timbangan: Rp '.number_format($linePrice, 0, ',', '.');
        }

        $this->cart[] = ['product_id' => $product->id, 'product_variant_id' => null, 'qty' => 1, 'modifier_ids' => [], 'notes' => $notes, 'custom_price' => $linePrice];
        $this->dispatch('pos-cart-state-changed', dirty: true);
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
            $groupModifierIds = $group ? $group->modifiers->pluck('id')->toArray() : Modifier::where('modifier_group_id', $groupId)->pluck('id')->toArray();
            $this->pendingModifierIds = array_values(array_diff($this->pendingModifierIds, $groupModifierIds));
            $this->pendingModifierIds[] = $modifierId;

            return;
        }

        if (in_array($modifierId, $this->pendingModifierIds, true)) {
            $this->pendingModifierIds = array_values(array_diff($this->pendingModifierIds, [$modifierId]));

            return;
        }

        if ($group && $group->max_select !== null && $group->max_select > 0) {
            $groupModifierIds = $group->modifiers->pluck('id')->toArray();
            if (count(array_intersect($this->pendingModifierIds, $groupModifierIds)) >= $group->max_select) {
                $this->dispatch('toast', message: "Maksimal {$group->max_select} opsi untuk {$group->name}.", type: 'warning');

                return;
            }
        }
        $this->pendingModifierIds[] = $modifierId;
    }

    public function confirmAddToCart(
        ?int $variantId = null,
        ?int $uomId = null,
        ?array $modifierIds = null,
        ?int $qty = null,
        ?string $notes = null
    ): void {
        if ($this->pendingOrderId !== null) {
            $this->addError('cart', 'Pending self-orders are read-only and cannot be modified.');

            return;
        }

        if ($variantId !== null) {
            $this->pendingVariantId = $variantId;
        }
        if ($uomId !== null) {
            $this->pendingUomId = $uomId;
        }
        if ($modifierIds !== null) {
            $this->pendingModifierIds = $modifierIds;
        }
        if ($qty !== null) {
            $this->pendingQty = max(1, $qty);
        }
        if ($notes !== null) {
            $this->pendingNotes = trim($notes) !== '' ? trim($notes) : null;
        }

        if (! $this->selectingProductId) {
            return;
        }

        $product = Product::with([
            'modifierGroups.modifiers',
            'variants' => fn ($q) => $q->where('is_active', true),
            'unitConversions',
        ])->findOrFail($this->selectingProductId);

        $storeId = app(TenantContext::class)->getStoreId();

        try {
            $this->cart = app(PosCartCoordinatorService::class)->validateAndBuildCartItem(
                $product,
                $this->cart,
                [
                    'variant_id' => $this->pendingVariantId,
                    'uom_id' => $this->pendingUomId,
                    'modifier_ids' => $this->pendingModifierIds,
                    'qty' => $this->pendingQty,
                    'notes' => $this->pendingNotes,
                ],
                $storeId
            );
            $this->closeModifierPicker();
            $this->dispatch('pos-cart-state-changed', dirty: true);
        } catch (ValidationException $e) {
            $this->addError('cart', collect($e->errors())->flatten()->first());
        }
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
        if (! app(CatalogPriceResolverService::class)->isAvailableInStore($product, $storeId)) {
            $this->addError('cart', "Product '{$product->product_name}' is not available at this branch.");

            return;
        }

        if ($product->variants->where('is_active', true)->isNotEmpty()) {
            $this->selectingVariantProductId = $productId;
            $this->openModifierPicker($productId);

            return;
        }

        if ($product->modifierGroups->isNotEmpty() || $product->unitConversions->isNotEmpty()) {
            $this->openModifierPicker($productId);

            return;
        }

        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $product->id && empty($item['product_variant_id']) && empty($item['uom_id']) && empty($item['modifier_ids']) && empty($item['notes'])) {
                $this->cart[$index]['qty']++;
                $this->dispatch('pos-cart-state-changed', dirty: true);

                return;
            }
        }

        $this->cart[] = ['product_id' => $product->id, 'product_variant_id' => null, 'uom_id' => null, 'qty' => 1, 'modifier_ids' => [], 'notes' => null];
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

    #[Computed]
    public function cartLines()
    {
        return app(PosCartCoordinatorService::class)->buildCartLines($this->cart, $this->customerId, app(TenantContext::class)->getStoreId());
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
        return (int) collect($this->cartLines)->sum('order_subtotal');
    }

    public function configuratorTotalPrice(): float
    {
        return app(PosCartCoordinatorService::class)->calculateConfiguratorPrice(
            $this->selectingProductId,
            $this->pendingVariantId,
            $this->pendingUomId,
            $this->pendingModifierIds,
            $this->pendingQty
        );
    }

    #[Computed]
    public function formattedModalTotal(): string
    {
        return 'Rp '.number_format($this->configuratorTotalPrice(), 0, ',', '.');
    }

    #[Computed]
    public function isConfiguratorValid(): bool
    {
        return app(PosCartCoordinatorService::class)->isConfiguratorValid(
            $this->selectingProductId,
            $this->pendingVariantId,
            $this->pendingModifierIds
        );
    }
}
