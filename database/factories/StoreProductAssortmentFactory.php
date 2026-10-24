<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\StoreProductAssortment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreProductAssortment>
 */
class StoreProductAssortmentFactory extends Factory
{
    protected $model = StoreProductAssortment::class;

    public function definition(): array
    {
        return [
            'store_id' => 1,
            'product_id' => Product::factory(),
            'is_available' => true,
            'store_price_override' => null,
            'allowed_channels' => ['dine_in', 'takeaway', 'delivery'],
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn () => [
            'is_available' => false,
        ]);
    }

    public function withPriceOverride(float $price): static
    {
        return $this->state(fn () => [
            'store_price_override' => $price,
        ]);
    }
}
