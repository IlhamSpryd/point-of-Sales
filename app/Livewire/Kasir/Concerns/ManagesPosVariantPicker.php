<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\ProductVariant;
use Livewire\Attributes\Locked;

/**
 * Manages POS cashier variant picker modals and direct variant addition (ADR-108).
 */
trait ManagesPosVariantPicker
{
    #[Locked]
    public ?int $selectingVariantProductId = null;

    public ?int $pendingVariantId = null;

    public ?int $pendingUomId = null;

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

        if (! $variant->product->modifierGroups()->where('is_required', true)->exists()) {
            $this->closeModifierPicker();
            $this->closeVariantPicker();
            $this->addVariantToCart($variant);
            $this->dispatch('toast', message: "Added: {$variant->product->product_name} ({$variant->variant_name})", type: 'success');
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
            if ($item['product_id'] === $product->id && ($item['product_variant_id'] ?? null) === $variant->id && empty($item['modifier_ids']) && empty($item['notes'])) {
                $this->cart[$index]['qty']++;
                $this->dispatch('pos-cart-state-changed', dirty: true);

                return;
            }
        }

        $this->cart[] = ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 1, 'modifier_ids' => [], 'notes' => null];
        $this->dispatch('pos-cart-state-changed', dirty: true);
    }
}
