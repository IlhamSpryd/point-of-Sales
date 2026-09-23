<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Ingredients
            $kopi = Ingredient::updateOrCreate(['name' => 'Kopi Arabica Blend'], [
                'ingredient_code' => 'ING-KOP-001',
                'unit' => 'g',
                'cost_per_unit' => 150, // Rp 150/g (Rp 150.000/kg)
                'reorder_level' => 1000,
                'current_stock' => 5000,
                'is_active' => true,
            ]);

            $susu = Ingredient::updateOrCreate(['name' => 'Susu Segar'], [
                'ingredient_code' => 'ING-SUS-001',
                'unit' => 'ml',
                'cost_per_unit' => 20, // Rp 20/ml (Rp 20.000/L)
                'reorder_level' => 2000,
                'current_stock' => 8000,
                'is_active' => true,
            ]);

            $gula = Ingredient::updateOrCreate(['name' => 'Sirup Gula Aren'], [
                'ingredient_code' => 'ING-GUL-001',
                'unit' => 'ml',
                'cost_per_unit' => 30, // Rp 30/ml
                'reorder_level' => 1000,
                'current_stock' => 3000,
                'is_active' => true,
            ]);

            $cup = Ingredient::updateOrCreate(['name' => 'Cup Plastik 16oz'], [
                'ingredient_code' => 'ING-CUP-001',
                'unit' => 'pcs',
                'cost_per_unit' => 800, // Rp 800/pcs
                'reorder_level' => 100,
                'current_stock' => 350,
                'is_active' => true,
            ]);

            // Add Initial Stock Movements (Purchase Receipts)
            $ingredients = [$kopi, $susu, $gula, $cup];
            foreach ($ingredients as $ing) {
                IngredientStockMovement::updateOrCreate([
                    'ingredient_id' => $ing->id,
                    'type' => 'purchase_receipt',
                ], [
                    'quantity' => $ing->current_stock,
                    'unit_cost' => $ing->cost_per_unit,
                    'reason' => 'Initial Stock',
                    'idempotency_key' => Str::uuid()->toString(),
                    'created_at' => Carbon::now()->subDays(15),
                ]);
            }

            // Generate daily usage (sale_deduction) over the last 14 days to trigger AI Forecast
            for ($i = 14; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);

                // Simulate daily burn rate
                $kopiBurn = rand(100, 300); // 100g - 300g per day
                $susuBurn = rand(500, 1500); // 500ml - 1.5L per day
                $gulaBurn = rand(100, 400); // 100ml - 400ml per day
                $cupBurn = rand(10, 40); // 10 - 40 cups per day

                $burns = [
                    $kopi->id => ['qty' => $kopiBurn, 'cost' => $kopi->cost_per_unit],
                    $susu->id => ['qty' => $susuBurn, 'cost' => $susu->cost_per_unit],
                    $gula->id => ['qty' => $gulaBurn, 'cost' => $gula->cost_per_unit],
                    $cup->id => ['qty' => $cupBurn, 'cost' => $cup->cost_per_unit],
                ];

                foreach ($burns as $ingId => $data) {
                    IngredientStockMovement::create([
                        'ingredient_id' => $ingId,
                        'type' => 'sale_deduction',
                        'quantity' => -$data['qty'],
                        'unit_cost' => $data['cost'],
                        'reason' => 'Daily Sales Usage',
                        'idempotency_key' => Str::uuid()->toString(),
                        'created_at' => $date,
                    ]);
                }
            }

            // 2. Categories & Products
            $kategori = Category::updateOrCreate(['category_name' => 'Kopi']);

            $kopiSusu = Product::updateOrCreate(['product_code' => 'PRD-KOP-001'], [
                'product_name' => 'Es Kopi Susu Aren',
                'product_description' => 'Kopi susu dengan gula aren asli',
                'category_id' => $kategori->id,
                'product_price' => 25000,
                'stock' => 999, // Unmanaged if using ingredients
                'is_active' => true,
            ]);

            // Sync BOM for Kopi Susu
            $kopiSusu->ingredients()->sync([
                $kopi->id => ['quantity_required' => 18], // 18g Kopi
                $susu->id => ['quantity_required' => 150], // 150ml Susu
                $gula->id => ['quantity_required' => 30], // 30ml Gula Aren
                $cup->id => ['quantity_required' => 1], // 1 Cup
            ]);

            // 3. Orders (to populate dashboard charts)
            // Generate orders for the last 7 days
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $numOrders = rand(5, 20);

                for ($j = 0; $j < $numOrders; $j++) {
                    $qty = rand(1, 4);
                    $total = 25000 * $qty;

                    $methods = ['cash', 'qris', 'ewallet'];
                    $method = $methods[array_rand($methods)];

                    $order = Order::forceCreate([
                        'user_id' => 1, // Assume Admin is 1
                        'order_code' => 'ORD-'.$date->format('Ymd').'-'.Str::upper(Str::random(4)),
                        'order_date' => $date->format('Y-m-d'),
                        'subtotal_amount' => $total,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'service_charge_amount' => 0,
                        'order_amount' => $total,
                        'order_type' => 'takeaway',
                        'order_status' => 'completed',
                        'payment_method' => $method,
                        'idempotency_key' => Str::uuid()->toString(),
                        'created_at' => $date->copy()->addHours(rand(8, 20))->addMinutes(rand(0, 59)),
                    ]);

                    OrderItem::forceCreate([
                        'order_id' => $order->id,
                        'product_id' => $kopiSusu->id,
                        'qty' => $qty,
                        'order_price' => 25000,
                        'order_subtotal' => $total,
                        'options' => json_encode([]),
                        'preparation_status' => 'ready',
                        'created_at' => $order->created_at,
                    ]);
                }
            }
        });
    }
}
