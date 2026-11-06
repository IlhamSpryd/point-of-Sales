<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Customers\Models\Voucher;
use App\Modules\Products\Models\PriceListItem;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\TaxClass;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * PricingPipelineService — Mesin pipeline kalkulasi harga 7-tahap deterministik.
 *
 * Mengikuti spesifikasi Section 4 Modul 04 PRC:
 * [ Base Price ]
 *       │
 *       ▼
 * [ Tahap 1: Price List Override ] (Cabang / Pelanggan)
 *       │
 *       ▼
 * [ Tahap 2: Volume Tiers (Harga Grosir) ]
 *       │
 *       ▼
 * [ Tahap 3: Item-Level Promos ]
 *       │
 *       ▼
 * [ Tahap 4: Cart-Level Voucher / Kupon ]
 *       │
 *       ▼
 * [ Tahap 5: Diskon Manual Kasir ]
 *       │
 *       ▼
 * [ Tahap 6: Evaluasi Margin Protection (Harga Jual >= HPP + Margin Min) ]
 *       │
 *       ▼
 * [ Tahap 7: Pajak & Biaya Layanan ]
 *       │
 *       ▼
 * [ Nilai Akhir Payable Total ]
 */
class PricingPipelineService
{
    public function __construct(
        private readonly MarginProtectionGuard $marginProtectionGuard
    ) {}

    /**
     * Eksekusi pipeline kalkulasi harga item lengkap.
     *
     * @param  array{
     *     tenant_id: int,
     *     store_id: int,
     *     product_id: int,
     *     variant_id?: int|null,
     *     quantity: int|float,
     *     customer_id?: int|null,
     *     price_list_id?: int|null,
     *     voucher_code?: string|null,
     *     manual_discount_amount?: float|string|null,
     *     manual_discount_percent?: float|null,
     *     supervisor_pin?: string|null,
     *     service_charge_percent?: float|null,
     * } $input
     * @return array{
     *     base_price: string,
     *     cost_price: string,
     *     stage1_price_list: string,
     *     stage2_volume_tier: string,
     *     stage3_item_promo: string,
     *     stage4_voucher_discount: string,
     *     stage5_manual_discount: string,
     *     stage6_unit_selling_price: string,
     *     subtotal: string,
     *     tax_amount: string,
     *     service_charge_amount: string,
     *     payable_total: string,
     *     applied_price_list_id: int|null,
     *     voucher_applied: string|null,
     *     margin_authorized: bool,
     * }
     */
    public function calculate(array $input): array
    {
        $tenantId = (int) $input['tenant_id'];
        $storeId = (int) $input['store_id'];
        $productId = (int) $input['product_id'];
        $variantId = isset($input['variant_id']) ? (int) $input['variant_id'] : null;
        $quantity = (float) $input['quantity'];
        /** @var numeric-string $qtyStr */
        $qtyStr = sprintf('%.2f', $quantity);

        // 0. Ambil Data Produk / Varian
        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $productId)
            ->first();

        if (! $product) {
            throw new RuntimeException("Product #{$productId} not found.");
        }

        $variant = null;
        if ($variantId !== null) {
            $variant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $variantId)
                ->first();
        }

        $rawBasePrice = $variant && $variant->price_override !== null
            ? (string) $variant->price_override
            : (string) ($product->product_price ?? $product->price ?? '0.00');
        $basePrice = bcadd((string) (float) $rawBasePrice, '0', 2);

        $rawCostPrice = $variant && $variant->standard_cost > 0
            ? (string) $variant->standard_cost
            : (string) ($product->standard_cost ?? $product->cost_price ?? '0.00');
        $costPrice = bcadd((string) (float) $rawCostPrice, '0', 2);

        /** @var numeric-string $currentUnitPrice */
        $currentUnitPrice = $basePrice;

        // Tahap 1: Evaluasi Daftar Harga (Price List Override)
        $appliedPriceListId = null;
        $priceListId = $input['price_list_id'] ?? null;
        if ($priceListId !== null) {
            $priceListItem = PriceListItem::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
                ->first();

            if ($priceListItem && (float) $priceListItem->price > 0) {
                /** @var numeric-string $currentUnitPrice */
                $currentUnitPrice = bcadd((string) (float) $priceListItem->price, '0', 2);
                $appliedPriceListId = (int) $priceListId;
            }
        }
        $stage1Price = $currentUnitPrice;

        // Tahap 2: Diskon Harga Grosir Bertingkat (Volume Tiers)
        if ($priceListId !== null) {
            $volumeTierItem = PriceListItem::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->where('min_quantity', '<=', $quantity)
                ->orderBy('min_quantity', 'desc')
                ->first();

            if ($volumeTierItem && (float) $volumeTierItem->price > 0) {
                /** @var numeric-string $currentUnitPrice */
                $currentUnitPrice = bcadd((string) (float) $volumeTierItem->price, '0', 2);
            }
        }
        $stage2Price = $currentUnitPrice;

        // Tahap 3: Aturan Promosi Produk (Item-Level Promos)
        // (Dapat diekspansi oleh modul promosi rule evaluator)
        $stage3Price = $currentUnitPrice;

        // Tahap 4: Diskon Kupon / Voucher Toko (Cart-Level Discount)
        $voucherDiscountTotal = '0.00';
        $voucherCode = $input['voucher_code'] ?? null;
        if (! empty($voucherCode)) {
            $voucher = Voucher::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $voucherCode)
                ->where('status', 'ACTIVE')
                ->where('valid_from', '<=', Carbon::now())
                ->where(function ($q) {
                    $q->whereNull('valid_until')->orWhere('valid_until', '>=', Carbon::now());
                })
                ->first();

            if ($voucher) {
                $subtotalBeforeVoucher = bcmul($currentUnitPrice, $qtyStr, 2);
                $minOrder = bcadd((string) (float) ($voucher->min_order_amount ?? 0), '0', 2);

                if (bccomp($subtotalBeforeVoucher, $minOrder, 2) >= 0) {
                    $rawDiscountType = $voucher->discount_type;
                    $discountType = $rawDiscountType instanceof \BackedEnum
                        ? $rawDiscountType->value
                        : strtoupper((string) $rawDiscountType);

                    if ($discountType === 'PERCENTAGE') {
                        $percentFraction = bcdiv((string) (float) $voucher->discount_value, '100', 4);
                        $calculatedDiscount = bcmul($subtotalBeforeVoucher, $percentFraction, 2);
                        if ($voucher->max_discount_amount) {
                            $maxDiscount = bcadd((string) (float) $voucher->max_discount_amount, '0', 2);
                            if (bccomp($calculatedDiscount, $maxDiscount, 2) > 0) {
                                $calculatedDiscount = $maxDiscount;
                            }
                        }
                        $voucherDiscountTotal = $calculatedDiscount;
                    } else {
                        // FIXED_AMOUNT
                        $voucherDiscountTotal = sprintf('%.2f', min((float) $subtotalBeforeVoucher, (float) $voucher->discount_value));
                    }
                }
            }
        }
        /** @var numeric-string $voucherDiscountTotal */
        $voucherUnitDiscount = bcdiv($voucherDiscountTotal, $qtyStr, 2);
        /** @var numeric-string $stage4Price */
        $stage4Price = bcsub($currentUnitPrice, $voucherUnitDiscount, 2);
        if (bccomp($stage4Price, '0.00', 2) < 0) {
            $stage4Price = '0.00';
        }
        $currentUnitPrice = $stage4Price;

        // Tahap 5: Diskon Manual Kasir
        $manualDiscountUnit = '0.00';
        if (! empty($input['manual_discount_percent']) && (float) $input['manual_discount_percent'] > 0) {
            $manualPercentFraction = bcdiv((string) $input['manual_discount_percent'], '100', 4);
            $manualDiscountUnit = bcmul($currentUnitPrice, $manualPercentFraction, 2);
        } elseif (! empty($input['manual_discount_amount']) && (float) $input['manual_discount_amount'] > 0) {
            $manualDiscountUnit = bcdiv((string) $input['manual_discount_amount'], $qtyStr, 2);
        }

        /** @var numeric-string $stage5Price */
        $stage5Price = bcsub($currentUnitPrice, $manualDiscountUnit, 2);
        if (bccomp($stage5Price, '0.00', 2) < 0) {
            $stage5Price = '0.00';
        }
        $currentUnitPrice = $stage5Price;

        // Tahap 6: Evaluasi Margin Protection Guard
        $supervisorPin = $input['supervisor_pin'] ?? null;
        $this->marginProtectionGuard->validateMargin(
            costPrice: $costPrice,
            finalPrice: $currentUnitPrice,
            supervisorPin: $supervisorPin,
            tenantId: $tenantId
        );
        $stage6Price = $currentUnitPrice;

        // Subtotal Jual Bersih
        $subtotal = bcmul($stage6Price, $qtyStr, 2);

        // Tahap 7: Pajak Pertambahan Nilai (PPN) & Biaya Layanan
        $taxAmount = '0.00';
        if ($product->tax_class_id) {
            $taxClass = TaxClass::withoutGlobalScopes()->find($product->tax_class_id);
            if ($taxClass) {
                $effectiveRate = (string) $taxClass->effectiveRate();
                $taxFraction = bcdiv($effectiveRate, '100', 4);

                if (! empty($taxClass->is_inclusive) || ! empty($product->is_tax_inclusive)) {
                    // Pajak Inklusif: Tax = Subtotal - (Subtotal / (1 + Rate))
                    $divisor = bcadd('1', $taxFraction, 4);
                    $baseSubtotal = bcdiv($subtotal, $divisor, 2);
                    $taxAmount = bcsub($subtotal, $baseSubtotal, 2);
                } else {
                    // Pajak Eksklusif: Tax = Subtotal * Rate
                    $taxAmount = bcmul($subtotal, $taxFraction, 2);
                }
            }
        }

        $serviceChargeAmount = '0.00';
        if (! empty($input['service_charge_percent']) && (float) $input['service_charge_percent'] > 0) {
            $scFraction = bcdiv((string) $input['service_charge_percent'], '100', 4);
            $serviceChargeAmount = bcmul($subtotal, $scFraction, 2);
        }

        // Nilai Akhir Payable Total
        $payableTotal = bcadd($subtotal, $serviceChargeAmount, 2);
        // Jika pajak eksklusif, tambahkan pajak ke total bayar
        $isInclusive = ($product->tax_class_id && ($taxClass->is_inclusive ?? false)) || $product->is_tax_inclusive;
        if (! $isInclusive) {
            $payableTotal = bcadd($payableTotal, $taxAmount, 2);
        }

        return [
            'base_price' => $basePrice,
            'cost_price' => $costPrice,
            'stage1_price_list' => $stage1Price,
            'stage2_volume_tier' => $stage2Price,
            'stage3_item_promo' => $stage3Price,
            'stage4_voucher_discount' => $voucherDiscountTotal,
            'stage5_manual_discount' => bcmul($manualDiscountUnit, $qtyStr, 2),
            'stage6_unit_selling_price' => $stage6Price,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'service_charge_amount' => $serviceChargeAmount,
            'payable_total' => $payableTotal,
            'applied_price_list_id' => $appliedPriceListId,
            'voucher_applied' => $voucherCode,
            'margin_authorized' => $supervisorPin !== null,
        ];
    }
}
