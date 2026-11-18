<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Services;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Modifier;
use App\Modules\Organization\Models\Setting;
use App\Modules\Pricing\Services\CatalogPriceResolverService;
use Illuminate\Validation\ValidationException;

/**
 * Pure calculation domain service for order subtotals, tax, discounts, and item line pricing (ADR-106 / ADR-108).
 */
class OrderTotalsCalculatorService
{
    /**
     * Calculate order tax and rounded total.
     *
     * @return array{tax_amount: int, total_amount: int}
     */
    public function calculateOrderTotals(float $subtotalAmount, float $discountAmount = 0): array
    {
        $discountedSubtotal = max(0, $subtotalAmount - $discountAmount);
        $tenantId = app(TenantContext::class)->getTenantId();
        $taxRate = $tenantId !== null ? (float) Setting::get('tax_rate', config('pos.tax_rate', 0.11)) : (float) config('pos.tax_rate', 0.11);
        $taxAmount = (int) round($discountedSubtotal * $taxRate);
        $totalAmount = (int) ($discountedSubtotal + $taxAmount);

        $roundingValue = $tenantId !== null ? (int) Setting::get('rounding_value', config('pos.rounding_value', 100)) : (int) config('pos.rounding_value', 100);
        $roundingBehavior = $tenantId !== null ? (string) Setting::get('rounding_behavior', config('pos.rounding_behavior', 'ROUND_NEAREST')) : (string) config('pos.rounding_behavior', 'ROUND_NEAREST');

        if ($roundingValue <= 0 || $roundingBehavior === 'NONE') {
            return [
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
            ];
        }

        $totalAmount = (int) match ($roundingBehavior) {
            'ROUND_NEAREST' => round($totalAmount / $roundingValue) * $roundingValue,
            'ROUND_UP' => ceil($totalAmount / $roundingValue) * $roundingValue,
            'ROUND_DOWN' => floor($totalAmount / $roundingValue) * $roundingValue,
            default => round($totalAmount),
        };

        return [
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * Resolve order item line details, price, modifiers, and subtotal.
     *
     * @param  array<int, int|string>  $modifierIds
     * @return array<string, mixed>
     */
    public function resolveOrderItemLine(
        Product $product,
        array $modifierIds,
        int $qty,
        ?string $notes,
        ?int $variantId = null,
        ?int $customerId = null,
        ?string $channel = 'pos_cashier',
        ?float $customPrice = null,
        ?int $uomId = null
    ): array {
        $productGroups = $product->modifierGroups;
        $requiredGroupIds = $productGroups->where('is_required', true)->pluck('id');

        $selectedModifiers = Modifier::whereIn('id', $modifierIds)->get();
        $selectedGroupIds = $selectedModifiers->pluck('modifier_group_id')->unique();

        foreach ($requiredGroupIds as $groupId) {
            if (! $selectedGroupIds->contains($groupId)) {
                throw ValidationException::withMessages([
                    'items' => "Required options for {$product->product_name} are incomplete.",
                ]);
            }
        }

        $productGroupIds = $productGroups->pluck('id');
        $foreignModifiers = $selectedModifiers->reject(
            fn ($m) => $productGroupIds->contains($m->modifier_group_id)
        );
        if ($foreignModifiers->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => "One or more selected variants are not available for {$product->product_name}.",
            ]);
        }

        foreach ($productGroups as $group) {
            $selectedForGroup = $selectedModifiers->where('modifier_group_id', $group->id)->count();
            if ($group->selection_type === 'single' && $selectedForGroup > 1) {
                throw ValidationException::withMessages(['items' => "Only one option may be selected for {$group->name} on {$product->product_name}."]);
            }
        }

        $storeId = app(TenantContext::class)->getStoreId();
        $baseProductPrice = app(CatalogPriceResolverService::class)->resolvePrice(
            product: $product,
            storeId: $storeId,
            variantId: $variantId,
            quantity: $qty,
            customerId: $customerId,
            loyaltyTierId: null,
            channel: $channel,
            uomId: $uomId,
            customPrice: $customPrice
        );
        $extraPrice = $selectedModifiers->sum('extra_price');
        $unitPrice = $baseProductPrice + $extraPrice;
        $lineSubtotal = $unitPrice * $qty;

        return [
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'uom_id' => $uomId,
            'product_name' => $product->product_name,
            'qty' => $qty,
            'order_price' => $unitPrice,
            'order_subtotal' => $lineSubtotal,
            'notes' => $notes,
            'preparation_status' => 'pending',
            'options' => $selectedModifiers->map(fn ($m) => [
                'modifier_id' => $m->id, 'name' => $m->name, 'extra_price' => $m->extra_price,
            ])->values()->toArray(),
        ];
    }

    /**
     * Scaled comparison helper.
     */
    public function compareScaled(string|float $a, string|float $b, int $scale = 4): int
    {
        $factor = 10 ** $scale;
        $aInt = (int) round(((float) $a) * $factor);
        $bInt = (int) round(((float) $b) * $factor);

        return $aInt <=> $bInt;
    }
}
