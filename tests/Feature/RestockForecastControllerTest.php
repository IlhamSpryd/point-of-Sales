<?php

namespace Tests\Feature;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestockForecastControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_forecasts_based_on_sale_deduction_movements(): void
    {
        $role = Role::firstOrCreate(['name' => 'Owner'], ['description' => 'Owner']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $ingredient = Ingredient::create([
            'name' => 'Biji Kopi',
            'unit' => 'gram',
            'cost_per_unit' => 100,
            'is_active' => true,
            'current_stock' => 100,
            'reorder_level' => 20,
        ]);

        // Create some sale deductions within the last 14 days (split to ensure stable trend)
        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'type' => IngredientStockMovementTypeEnum::SaleDeduction->value,
            'quantity' => -14,
            'created_at' => Carbon::now()->subDays(10),
            'order_id' => null,
            'order_item_id' => null,
            'idempotency_key' => uniqid('test_'),
        ]);
        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'type' => IngredientStockMovementTypeEnum::SaleDeduction->value,
            'quantity' => -14,
            'created_at' => Carbon::now()->subDays(3),
            'order_id' => null,
            'order_item_id' => null,
            'idempotency_key' => uniqid('test_'),
        ]);

        app(\App\Services\Analytics\RestockPredictionService::class)->recomputeAll();

        $response = $this->actingAs($user)->getJson(route('analytics.restock-forecasts'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'ingredient_id',
                    'name',
                    'unit',
                    'current_stock',
                    'avg_daily_consumption',
                    'projected_days_remaining',
                    'projected_stockout_at',
                    'suggested_reorder_qty',
                    'trend',
                    'computed_at',
                ],
            ],
        ]);

        // Math validation:
        // Total consumption in last 14 days: 28.
        // avg_daily_consumption = 28 / 14 = 2.
        // projected_days_remaining = current_stock (100) / 2 = 50.
        $data = $response->json('data.0');
        $this->assertEquals(2, $data['avg_daily_consumption']);
        $this->assertEquals(50, $data['projected_days_remaining']);
        // forwardNeed = 2 * 14 = 28. buffer = 100 - 20 = 80. suggested = max(0, 28 - 80) = 0.
        $this->assertEquals(0, $data['suggested_reorder_qty']);
        $this->assertEquals('stable', $data['trend']);
    }

    public function test_it_suggests_reorder_when_days_remaining_under_7(): void
    {
        $role = Role::firstOrCreate(['name' => 'Owner'], ['description' => 'Owner']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $ingredient = Ingredient::create([
            'name' => 'Biji Kopi',
            'unit' => 'gram',
            'cost_per_unit' => 100,
            'is_active' => true,
            'current_stock' => 10,
            'reorder_level' => 20,
        ]);

        // Consume 28 in last 14 days -> 2/day
        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'type' => IngredientStockMovementTypeEnum::SaleDeduction->value,
            'quantity' => -28,
            'created_at' => Carbon::now()->subDays(5),
            'order_id' => null,
            'order_item_id' => null,
            'idempotency_key' => uniqid('test_'),
        ]);

        app(\App\Services\Analytics\RestockPredictionService::class)->recomputeAll();

        $response = $this->actingAs($user)->getJson(route('analytics.restock-forecasts'));

        $response->assertStatus(200);

        // Math validation:
        // avg_daily = 2.
        // days remaining = 10 / 2 = 5 days.
        // forwardNeed = 2 * 14 = 28. buffer = 10 - 20 = -10. suggested = max(0, 28 - (-10)) = 38.
        $data = $response->json('data.0');
        $this->assertEquals(5, $data['projected_days_remaining']);
        $this->assertEquals(38, $data['suggested_reorder_qty']);
    }
}
