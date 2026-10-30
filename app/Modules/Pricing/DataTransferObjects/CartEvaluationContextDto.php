<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DataTransferObjects;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final readonly class CartEvaluationContextDto
{
    public CarbonInterface $now;

    /**
     * @param  array<int, array{
     *     productId: int,
     *     categoryId?: int|null,
     *     variantId?: int|null,
     *     quantity: int|float,
     *     unitPrice: float,
     *     options?: array,
     *     lineIndex: int
     * }>  $items
     */
    public function __construct(
        public int $tenantId,
        public int $storeId,
        public string $channel = 'pos_cashier',
        public ?int $customerId = null,
        public ?int $customerGroupId = null,
        CarbonInterface|string|null $now = null,
        public array $items = [],
    ) {
        if ($now instanceof CarbonInterface) {
            $this->now = $now;
        } elseif (is_string($now)) {
            $this->now = Carbon::parse($now);
        } else {
            $this->now = now();
        }
    }

    public function getSubtotal(): float
    {
        $sum = 0.0;
        foreach ($this->items as $item) {
            $price = (float) $item['unitPrice'];
            $qty = (float) $item['quantity'];
            $sum += $price * $qty;
        }

        return $sum;
    }
}
