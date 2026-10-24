<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'product_name' => $this->faker->words(3, true),
            'product_code' => strtoupper($this->faker->bothify('PRD-####-????')),
            'product_price' => $this->faker->numberBetween(10000, 100000),
            'stock' => 100,
            'product_type' => 'standard',
            'status' => 'active',
            'track_inventory' => true,
            'costing_method' => 'standard',
            'standard_cost' => 5000.00,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'product_type' => 'service',
            'track_inventory' => false,
            'stock' => 0,
        ]);
    }

    public function nonInventory(): static
    {
        return $this->state(fn () => [
            'product_type' => 'non_inventory',
            'track_inventory' => false,
            'stock' => 0,
        ]);
    }

    public function bundle(): static
    {
        return $this->state(fn () => [
            'product_type' => 'bundle',
            'track_inventory' => false,
            'stock' => 0,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => 'inactive',
            'is_active' => false,
        ]);
    }
}
