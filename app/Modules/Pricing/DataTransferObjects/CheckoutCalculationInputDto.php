<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DataTransferObjects;

use Carbon\Carbon;

class CheckoutCalculationInputDto
{
    /**
     * @param array<int, array{
     *     product_id: int,
     *     product_variant_id?: ?int,
     *     quantity: float|int,
     *     custom_price?: ?float,
     *     modifier_ids?: array<int, int>,
     *     item_discount_amount?: float|int,
     *     item_discount_id?: ?int,
     *     notes?: ?string
     * }> $items
     */
    public function __construct(
        public int $tenantId,
        public int $storeId,
        public string $channel = 'pos_cashier',
        public ?int $customerId = null,
        public ?int $customerGroupId = null,
        public ?int $loyaltyTierId = null,
        public array $items = [],
        public ?string $voucherCode = null,
        public ?int $voucherId = null,
        public ?int $discountId = null,
        public float $manualDiscountAmount = 0.0,
        public float $manualDiscountPercent = 0.0,
        public ?int $manualDiscountApprovalId = null,
        public bool $hasCashPayment = false,
        public ?float $serviceChargeRate = null,
        public ?float $taxRate = null,
        public ?bool $isTaxInclusive = null,
        public ?Carbon $transactionDate = null
    ) {}
}
