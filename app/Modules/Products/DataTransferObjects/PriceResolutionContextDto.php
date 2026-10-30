<?php

declare(strict_types=1);

namespace App\Modules\Products\DataTransferObjects;

use Carbon\CarbonInterface;

final class PriceResolutionContextDto
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $productId,
        public readonly int|string|null $storeId = null,
        public readonly ?int $variantId = null,
        public readonly ?int $uomId = null,
        public readonly float|int $quantity = 1.0,
        public readonly ?int $customerId = null,
        public readonly ?int $customerGroupId = null,
        public readonly ?int $loyaltyTierId = null,
        public readonly ?string $channel = null,
        public readonly ?CarbonInterface $transactionDate = null,
        public readonly ?float $customPrice = null,
        public readonly ?\App\Models\Product $product = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (int) ($data['tenant_id'] ?? 0),
            productId: (int) ($data['product_id'] ?? 0),
            storeId: $data['store_id'] ?? null,
            variantId: isset($data['variant_id']) ? (int) $data['variant_id'] : null,
            uomId: isset($data['uom_id']) ? (int) $data['uom_id'] : null,
            quantity: isset($data['quantity']) ? (float) $data['quantity'] : 1.0,
            customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            customerGroupId: isset($data['customer_group_id']) ? (int) $data['customer_group_id'] : null,
            loyaltyTierId: isset($data['loyalty_tier_id']) ? (int) $data['loyalty_tier_id'] : null,
            channel: $data['channel'] ?? null,
            transactionDate: $data['transaction_date'] ?? null,
            customPrice: isset($data['custom_price']) ? (float) $data['custom_price'] : null,
        );
    }
}
