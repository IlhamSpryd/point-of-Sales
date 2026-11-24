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
     * Calculate order tax, service charge, and rounded total using BCMath / Money value semantics.
     *
     * @return array{tax_amount: int, total_amount: int, dpp_amount: int, service_charge_amount: int, rounding_adjustment: int}
     */
    public function calculateOrderTotals(
        float|int|string $subtotalAmount,
        float|int|string $discountAmount = 0,
        float|int|string $serviceChargeAmount = 0,
        ?bool $taxInclusive = null
    ): array {
        $subtotalStr = bcadd((string) $subtotalAmount, '0', 4);
        $discountStr = bcadd((string) $discountAmount, '0', 4);

        $dppStr = bccomp($subtotalStr, $discountStr, 4) > 0
            ? bcsub($subtotalStr, $discountStr, 4)
            : '0.0000';

        $tenantId = app(TenantContext::class)->getTenantId();

        // 1. Service Charge Calculation
        $scRateStr = $tenantId !== null
            ? (string) Setting::get('service_charge_rate', '0.0')
            : '0.0';

        $scInputStr = bcadd((string) $serviceChargeAmount, '0', 4);
        if (bccomp($scInputStr, '0.0000', 4) > 0) {
            $scAmountStr = $scInputStr;
        } elseif (bccomp($scRateStr, '0.0', 4) > 0) {
            $scAmountStr = bcmul($dppStr, $scRateStr, 4);
        } else {
            $scAmountStr = '0.0000';
        }
        $scAmountInt = (int) round((float) $scAmountStr, 0, PHP_ROUND_HALF_UP);

        // 2. Tax Calculation
        $taxRateStr = $tenantId !== null
            ? (string) Setting::get('tax_rate', (string) config('pos.tax_rate', '0.11'))
            : (string) config('pos.tax_rate', '0.11');

        $isTaxInclusive = $taxInclusive ?? ($tenantId !== null ? (bool) Setting::get('tax_inclusive', false) : false);

        if ($isTaxInclusive) {
            // In inclusive mode: DPP includes tax: dppExTax = dpp / (1 + tax_rate)
            $divisor = bcadd('1', $taxRateStr, 6);
            $dppExTax = bccomp($divisor, '0', 6) > 0 ? bcdiv($dppStr, $divisor, 4) : $dppStr;
            $taxAmountRaw = bcsub($dppStr, $dppExTax, 4);
            $taxAmountInt = (int) round((float) $taxAmountRaw, 0, PHP_ROUND_HALF_UP);
            $unroundedTotal = (int) round((float) bcadd($dppStr, (string) $scAmountInt, 4), 0, PHP_ROUND_HALF_UP);
        } else {
            // Standar F&B / PB1: Tax = DPP * tax_rate
            $taxAmountRaw = bcmul($dppStr, $taxRateStr, 4);
            $taxAmountInt = (int) round((float) $taxAmountRaw, 0, PHP_ROUND_HALF_UP);
            $unroundedTotal = (int) round((float) bcadd(bcadd($dppStr, (string) $scAmountInt, 4), (string) $taxAmountInt, 4), 0, PHP_ROUND_HALF_UP);
        }

        // 3. Cash Rounding
        $roundingValue = $tenantId !== null
            ? (int) Setting::get('rounding_value', config('pos.rounding_value', 100))
            : (int) config('pos.rounding_value', 100);

        $roundingBehavior = $tenantId !== null
            ? (string) Setting::get('rounding_behavior', config('pos.rounding_behavior', 'ROUND_NEAREST'))
            : (string) config('pos.rounding_behavior', 'ROUND_NEAREST');

        if ($roundingValue <= 0 || $roundingBehavior === 'NONE') {
            return [
                'tax_amount' => $taxAmountInt,
                'total_amount' => $unroundedTotal,
                'dpp_amount' => (int) round((float) $dppStr, 0, PHP_ROUND_HALF_UP),
                'service_charge_amount' => $scAmountInt,
                'rounding_adjustment' => 0,
            ];
        }

        $roundedTotal = (int) match ($roundingBehavior) {
            'ROUND_NEAREST' => round($unroundedTotal / $roundingValue) * $roundingValue,
            'ROUND_UP' => ceil($unroundedTotal / $roundingValue) * $roundingValue,
            'ROUND_DOWN' => floor($unroundedTotal / $roundingValue) * $roundingValue,
            default => round($unroundedTotal),
        };

        $roundingAdjustment = $roundedTotal - $unroundedTotal;

        return [
            'tax_amount' => $taxAmountInt,
            'total_amount' => $roundedTotal,
            'dpp_amount' => (int) round((float) $dppStr, 0, PHP_ROUND_HALF_UP),
            'service_charge_amount' => $scAmountInt,
            'rounding_adjustment' => $roundingAdjustment,
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
        $unitPrice = (float) bcadd((string) $baseProductPrice, (string) $extraPrice, 2);
        $lineSubtotal = (float) bcmul((string) $unitPrice, (string) $qty, 2);

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
