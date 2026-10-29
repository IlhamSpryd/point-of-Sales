<?php

declare(strict_types=1);

namespace App\Modules\Products\DataTransferObjects;

final class ResolvedPriceDto
{
    public const SOURCE_CUSTOMER_CONTRACT = 'CUSTOMER_CONTRACT';

    public const SOURCE_CUSTOMER_GROUP = 'CUSTOMER_GROUP';

    public const SOURCE_STORE_PRICE_LIST = 'STORE_PRICE_LIST';

    public const SOURCE_STORE_OVERRIDE = 'BRANCH_OVERRIDE';

    public const SOURCE_VARIANT_OVERRIDE = 'VARIANT_OVERRIDE';

    public const SOURCE_BASE_PRODUCT = 'BASE_PRODUCT';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly float $unitPrice,
        public readonly float $baseUnitPrice,
        public readonly ?int $priceListId = null,
        public readonly ?int $priceListItemId = null,
        public readonly string $pricingSource = self::SOURCE_BASE_PRODUCT,
        public readonly bool $allowPromoStacking = false,
        public readonly ?float $discountPercentage = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'base_unit_price' => $this->baseUnitPrice,
            'price_list_id' => $this->priceListId,
            'price_list_item_id' => $this->priceListItemId,
            'pricing_source' => $this->pricingSource,
            'allow_promo_stacking' => $this->allowPromoStacking,
            'discount_percentage' => $this->discountPercentage,
            'meta' => $this->meta,
        ];
    }
}
