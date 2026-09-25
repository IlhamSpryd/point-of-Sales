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

        // Create some sale deductions within the last 30 days
        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'type' => IngredientStockMovementTypeEnum::SaleDeduction->value,
            'quantity' => -60,
            'created_at' => Carbon::now()->subDays(10),
            'order_id' => null,
            'order_item_id' => null,
            'idempotency_key' => uniqid('test_'),
        ]);

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
        // Total consumption in last 30 days: 60.
        // avg_daily_consumption = 60 / 30 = 2.
        // projected_days_remaining = current_stock (100) / 2 = 50.
        $data = $response->json('data.0');
        $this->assertEquals(2, $data['avg_daily_consumption']);
        $this->assertEquals(50, $data['projected_days_remaining']);
        // Because days remaining is > 7, suggested reorder qty should be max(0, reorder_level - current_stock) -> max(0, 20 - 100) = 0
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

        // Consume 60 in last 30 days -> 2/day
        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'type' => IngredientStockMovementTypeEnum::SaleDeduction->value,
            'quantity' => -60,
            'created_at' => Carbon::now()->subDays(10),
            'order_id' => null,
            'order_item_id' => null,
            'idempotency_key' => uniqid('test_'),
        ]);

        $response = $this->actingAs($user)->getJson(route('analytics.restock-forecasts'));

        $response->assertStatus(200);

        // Math validation:
        // avg_daily = 2.
        // days remaining = 10 / 2 = 5 days.
        // because 5 <= 7, suggested reorder qty = max(reorder_level - current_stock, avg_daily * 14 - current_stock)
        // max(20 - 10, 2 * 14 - 10) = max(10, 28 - 10) = max(10, 18) = 18.
        $data = $response->json('data.0');
        $this->assertEquals(5, $data['projected_days_remaining']);
        $this->assertEquals(18, $data['suggested_reorder_qty']);
    }
}
