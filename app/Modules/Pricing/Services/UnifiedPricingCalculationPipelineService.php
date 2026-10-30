<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Modules\Customers\Services\VoucherService;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationBreakdownDto;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationInputDto;
use App\Services\CatalogPriceResolverService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UnifiedPricingCalculationPipelineService
{
    public function __construct(
        protected CatalogPriceResolverService $catalogPriceResolver,
        protected PromotionEngineService $promotionEngine,
        protected PromotionCombinationEngine $combinationEngine,
        protected DiscountAllocationService $discountAllocationService,
        protected VoucherService $voucherService,
        protected DiscountApprovalService $discountApprovalService
    ) {}

    /**
     * Mengeksekusi pipa kalkulasi harga terpadu 9 langkah kanonis PRD OD-12.
     */
    public function calculate(CheckoutCalculationInputDto $input): CheckoutCalculationBreakdownDto
    {
        $now = $input->transactionDate ?? Carbon::now();

        // -------------------------------------------------------------
        // LANGKAH 1: Line Subtotal (Unit price via resolver + modifiers)
        // -------------------------------------------------------------
        $lines = [];
        $grossSubtotalTotal = 0.0;
        $evalCartItems = [];

        // Preload items to eliminate N+1 queries during cart evaluation
        $productIds = array_map(fn ($item) => (int) $item['product_id'], $input->items);
        $variantIds = array_values(array_filter(array_map(
            fn ($item) => ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
            $input->items
        )));
        $allModifierIds = [];
        foreach ($input->items as $item) {
            if (! empty($item['modifier_ids'])) {
                foreach ($item['modifier_ids'] as $mId) {
                    $allModifierIds[] = (int) $mId;
                }
            }
        }

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $input->tenantId)
            ->whereIn('id', array_unique($productIds))
            ->get()
            ->keyBy('id');

        $variants = ! empty($variantIds)
            ? ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $input->tenantId)
                ->whereIn('id', array_unique($variantIds))
                ->get()
                ->keyBy('id')
            : collect();

        $modifiers = ! empty($allModifierIds)
            ? Modifier::whereIn('id', array_unique($allModifierIds))->get()->keyBy('id')
            : collect();

        foreach ($input->items as $idx => $item) {
            $productId = (int) $item['product_id'];
            $variantId = ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            $qty = max(1.0, (float) $item['quantity']);

            $product = $products->get($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => "Produk #{$productId} tidak ditemukan pada tenant ini.",
                ]);
            }

            $variant = $variantId ? $variants->get($variantId) : null;

            // Hitung harga opsi / modifier
            $serverExtraPrice = 0.0;
            if (! empty($item['modifier_ids'])) {
                foreach ($item['modifier_ids'] as $modId) {
                    $mod = $modifiers->get((int) $modId);
                    if ($mod) {
                        $serverExtraPrice += (float) $mod->extra_price;
                    }
                }
            }

            $customPrice = isset($item['custom_price']) ? (float) $item['custom_price'] : null;
            $baseUnitPrice = $this->catalogPriceResolver->resolvePrice(
                product: $product,
                storeId: $input->storeId,
                variantId: $variantId,
                quantity: $qty,
                customerId: $input->customerId,
                loyaltyTierId: $input->loyaltyTierId,
                channel: $input->channel,
                customPrice: $customPrice
            );

            $unitPrice = round($baseUnitPrice + $serverExtraPrice, 2);
            $lineGross = round($unitPrice * $qty, 2);
            $grossSubtotalTotal += $lineGross;

            $productName = (string) ($variant ? $variant->name : ($product->name ?? $product->product_name ?? 'Produk'));
            $sku = (string) ($variant ? $variant->sku : ($product->sku ?? ''));
            $hpp = (float) ($variant ? $variant->cost_price : ($product->standard_cost ?? $product->cost_price ?? $product->hpp ?? 0.0));

            $lines[$idx] = [
                'line_index' => $idx,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'category_id' => $product->category_id ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'gross_subtotal' => $lineGross,
                'item_discount' => 0.0,
                'net_subtotal' => $lineGross,
                'allocated_transaction_discount' => 0.0,
                'effective_subtotal' => $lineGross,
                'product_name_snapshot' => $productName,
                'sku_snapshot' => $sku,
                'hpp_snapshot' => $hpp,
                'price_source' => $customPrice !== null ? 'open_price' : 'catalog_resolver',
                'applied_promotions_snapshot' => [],
                'item_discount_amount' => (float) ($item['item_discount_amount'] ?? 0.0),
                'item_discount_id' => $item['item_discount_id'] ?? null,
            ];

            $evalCartItems[] = [
                'lineIndex' => $idx,
                'line_index' => $idx,
                'productId' => $productId,
                'product_id' => $productId,
                'categoryId' => $product->category_id ?? null,
                'category_id' => $product->category_id ?? null,
                'quantity' => $qty,
                'unitPrice' => $unitPrice,
                'unit_price' => $unitPrice,
            ];
        }

        // -------------------------------------------------------------
        // LANGKAH 2: Item Discounts (Promo otomatis item & diskon item)
        // -------------------------------------------------------------
        $evalContext = new CartEvaluationContextDto(
            tenantId: $input->tenantId,
            storeId: $input->storeId,
            channel: $input->channel,
            customerId: $input->customerId,
            customerGroupId: $input->customerGroupId,
            now: $now,
            items: $evalCartItems
        );

        $evalResult = $this->promotionEngine->evaluateCart($evalContext);

        $appliedCampaignsSummary = [];
        foreach ($evalResult->appliedPromotions as $promo) {
            $appliedCampaignsSummary[] = [
                'campaign_id' => $promo->campaignId,
                'rule_id' => $promo->ruleId,
                'reward_id' => $promo->rewardId,
                'type' => $promo->type,
                'discount_amount' => $promo->discountAmount,
                'description' => $promo->description,
                'target_line_index' => $promo->targetLineIndex,
            ];

            if ($promo->targetLineIndex !== null && isset($lines[$promo->targetLineIndex])) {
                $lines[$promo->targetLineIndex]['item_discount'] += $promo->discountAmount;
                $lines[$promo->targetLineIndex]['applied_promotions_snapshot'][] = [
                    'campaign_id' => $promo->campaignId,
                    'type' => $promo->type,
                    'discount_amount' => $promo->discountAmount,
                    'description' => $promo->description,
                ];
            }
        }

        // Terapkan diskon manual per item jika ada
        foreach ($lines as $idx => &$line) {
            if ($line['item_discount_amount'] > 0) {
                $line['item_discount'] += $line['item_discount_amount'];
            } elseif (! empty($line['item_discount_id'])) {
                $disc = Discount::find($line['item_discount_id']);
                if ($disc && $disc->is_active) {
                    $isPercentage = in_array(strtolower((string) $disc->type), ['percentage', 'percent'], true);
                    $val = $isPercentage
                        ? round($line['gross_subtotal'] * ((float) $disc->value / 100), 2)
                        : (float) $disc->value;
                    $line['item_discount'] += $val;
                }
            }

            // Cap item discount to gross subtotal
            $line['item_discount'] = min($line['gross_subtotal'], $line['item_discount']);
            $line['net_subtotal'] = round($line['gross_subtotal'] - $line['item_discount'], 2);
        }
        unset($line);

        $totalItemDiscounts = round(array_sum(array_column($lines, 'item_discount')), 2);

        // -------------------------------------------------------------
        // LANGKAH 3: Order Subtotal (sum LineNet)
        // -------------------------------------------------------------
        $orderSubtotal = round(array_sum(array_column($lines, 'net_subtotal')), 2);

        // -------------------------------------------------------------
        // LANGKAH 4: Transaction Discount Allocation (Order promo, voucher, manual)
        // -------------------------------------------------------------
        $orderDiscounts = [];

        // 4a. Order campaign otomatis
        if ($evalResult->totalOrderDiscounts > 0) {
            $orderDiscounts[] = [
                'type' => 'CAMPAIGN',
                'amount' => $evalResult->totalOrderDiscounts,
                'is_stackable' => false,
            ];
        }

        // 4b. Kupon / Voucher
        $voucherDiscountAmount = 0.0;
        if (! empty($input->voucherCode)) {
            $customer = $input->customerId ? Customer::find($input->customerId) : null;
            $vRes = $this->voucherService->validateVoucher(
                code: $input->voucherCode,
                customer: $customer,
                orderAmount: number_format($orderSubtotal, 2, '.', '')
            );
            if (! empty($vRes['is_valid'])) {
                $voucherDiscountAmount = (float) $vRes['discount_amount'];
                $orderDiscounts[] = [
                    'type' => 'VOUCHER',
                    'amount' => $voucherDiscountAmount,
                    'is_stackable' => false,
                    'code' => $input->voucherCode,
                ];
                $appliedCampaignsSummary[] = [
                    'type' => 'VOUCHER',
                    'code' => $input->voucherCode,
                    'discount_amount' => $voucherDiscountAmount,
                    'description' => "Voucher: {$input->voucherCode}",
                ];
            }
        }

        // 4c. Legacy Discount Model
        if (! empty($input->discountId)) {
            $orderDisc = Discount::find($input->discountId);
            if ($orderDisc && $orderDisc->is_active && $orderSubtotal >= (float) $orderDisc->min_purchase_amount) {
                $isPercentage = in_array(strtolower((string) $orderDisc->type), ['percentage', 'percent'], true);
                $val = $isPercentage
                    ? round($orderSubtotal * ((float) $orderDisc->value / 100), 2)
                    : (float) $orderDisc->value;
                if ($orderDisc->max_discount_amount > 0) {
                    $val = min($val, (float) $orderDisc->max_discount_amount);
                }
                $orderDiscounts[] = [
                    'type' => 'ORDER_DISCOUNT',
                    'amount' => $val,
                    'is_stackable' => (bool) $orderDisc->is_stackable,
                ];
            }
        }

        // 4d. Diskon Manual Transaksi
        $manualDiscount = 0.0;
        if ($input->manualDiscountPercent > 0) {
            $manualDiscount = round($orderSubtotal * ($input->manualDiscountPercent / 100), 2);
        } elseif ($input->manualDiscountAmount > 0) {
            $manualDiscount = round($input->manualDiscountAmount, 2);
        }

        if ($manualDiscount > 0) {
            $orderDiscounts[] = [
                'type' => 'MANUAL_DISCOUNT',
                'amount' => $manualDiscount,
                'is_stackable' => true,
            ];
            $appliedCampaignsSummary[] = [
                'type' => 'MANUAL_DISCOUNT',
                'discount_amount' => $manualDiscount,
                'description' => 'Diskon Manual Kasir',
            ];
        }

        // Evaluasi kombinasi diskon transaksi
        $totalTransactionDiscounts = $this->combinationEngine->resolveOrderDiscounts($orderDiscounts, $orderSubtotal);
        $totalTransactionDiscounts = min($orderSubtotal, $totalTransactionDiscounts);

        // Alokasikan diskon transaksi ke baris item via Largest Remainder Method
        $lineAllocationInputs = [];
        foreach ($lines as $l) {
            $lineAllocationInputs[] = [
                'id' => $l['line_index'],
                'effective_subtotal' => $l['net_subtotal'],
            ];
        }

        $allocatedMap = $this->discountAllocationService->allocate($totalTransactionDiscounts, $lineAllocationInputs);

        foreach ($lines as $idx => &$line) {
            $line['allocated_transaction_discount'] = $allocatedMap[$line['line_index']] ?? 0.0;
            $line['effective_subtotal'] = round($line['net_subtotal'] - $line['allocated_transaction_discount'], 2);
        }
        unset($line);

        // -------------------------------------------------------------
        // LANGKAH 5: DPP (Dasar Pengenaan Pajak)
        // -------------------------------------------------------------
        $dpp = max(0.0, round($orderSubtotal - $totalTransactionDiscounts, 2));

        // -------------------------------------------------------------
        // LANGKAH 6: Service Charge
        // -------------------------------------------------------------
        $serviceRate = $input->serviceChargeRate !== null
            ? (float) $input->serviceChargeRate
            : (float) Setting::get('service_charge_rate', 0.0);

        $serviceChargeAmount = round($dpp * $serviceRate, 2);

        // -------------------------------------------------------------
        // LANGKAH 7: Pajak (PB1 / PPN)
        // -------------------------------------------------------------
        $taxRate = $input->taxRate !== null
            ? (float) $input->taxRate
            : (float) Setting::get('tax_rate', config('pos.tax_rate', 0.11));

        $isTaxInclusive = $input->isTaxInclusive !== null
            ? (bool) $input->isTaxInclusive
            : (bool) Setting::get('tax_inclusive', false);

        if ($isTaxInclusive) {
            $dppExTax = round($dpp / (1.0 + $taxRate), 2);
            $taxAmount = round($dpp - $dppExTax, 2);
        } else {
            // Standar F&B: Pajak dihitung dari DPP
            $taxAmount = round($dpp * $taxRate, 2);
        }

        // -------------------------------------------------------------
        // LANGKAH 8: Pembulatan Kas (Cash Rounding Adjustment)
        // -------------------------------------------------------------
        $totalBeforeRounding = $isTaxInclusive
            ? round($dpp + $serviceChargeAmount, 2)
            : round($dpp + $serviceChargeAmount + $taxAmount, 2);

        $cashRoundingAdjustment = 0.0;
        if ($input->hasCashPayment) {
            // Bulatkan ke bawah ke kelipatan Rp100 terdekat
            $roundedTotal = floor($totalBeforeRounding / 100) * 100;
            $cashRoundingAdjustment = round($roundedTotal - $totalBeforeRounding, 2);
        }

        // -------------------------------------------------------------
        // LANGKAH 9: Grand Total
        // -------------------------------------------------------------
        $grandTotal = round($totalBeforeRounding + $cashRoundingAdjustment, 2);

        return new CheckoutCalculationBreakdownDto(
            lineSubtotal: $grossSubtotalTotal,
            totalItemDiscounts: $totalItemDiscounts,
            orderSubtotal: $orderSubtotal,
            totalTransactionDiscounts: $totalTransactionDiscounts,
            dpp: $dpp,
            serviceChargeRate: $serviceRate,
            serviceChargeAmount: $serviceChargeAmount,
            taxRate: $taxRate,
            isTaxInclusive: $isTaxInclusive,
            taxAmount: $taxAmount,
            cashRoundingAdjustment: $cashRoundingAdjustment,
            grandTotal: $grandTotal,
            lineBreakdowns: $lines,
            appliedPromotionsSummary: $appliedCampaignsSummary
        );
    }
}
