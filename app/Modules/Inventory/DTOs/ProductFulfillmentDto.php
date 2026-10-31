<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

readonly class ProductFulfillmentDto
{
    /**
     * @param  array<int, array{ingredient_id: int, name: string, required: string, available: string, unit: string}>  $missingIngredients
     */
    public function __construct(
        public bool $isFulfillable,
        public ?int $availablePortions = null,
        public bool $hasBom = false,
        public array $missingIngredients = [],
    ) {}
}
