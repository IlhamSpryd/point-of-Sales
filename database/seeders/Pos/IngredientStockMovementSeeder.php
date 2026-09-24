<?php

namespace Database\Seeders\Pos;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IngredientStockMovementSeeder: Generates ingredient-level stock movements.
 *
 * For each completed order item, creates 'out' movements per ingredient in the recipe.
 * Also generates periodic 'in' (restock) movements.
 *
 * IMPORTANT: ingredient_stock_movements is append-only — no UPDATE/DELETE.
 */
class IngredientStockMovementSeeder extends Seeder
{
    public function run(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '2G');

        // Load product ingredients
        $productIngredients = DB::table('product_ingredients')
            ->select('product_id', 'ingredient_id', 'quantity_required')
            ->get()
            ->groupBy('product_id');

        // Load completed orders with their items
        $inventoryUserIds = [32, 33]; // Inventory staff

        // Truncate first (before triggers — we need to disable them temporarily)
        // Since we can't update/delete, we must drop and recreate the trigger to truncate.
        // Instead, we'll just truncate with FK checks off.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        // Drop append-only triggers temporarily
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_update');
        DB::table('ingredient_stock_movements')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $totalMov = 0;
        $batch = [];
        $batchSize = 1000;

        // Process in day-based chunks to limit memory
        $now = Carbon::now();
        $startDate = $now->copy()->subDays(365);
        $today = $now->copy()->startOfDay();
        $totalDays = $startDate->diffInDays($today) + 1;

        $bar = $this->command->getOutput()->createProgressBar($totalDays);
        $bar->setFormat(' IngredientStock [day]: %current%/%max% [%bar%] %percent:3s%%');

        $date = $startDate->copy();

        while ($date->lte($today)) {
            $dateStr = $date->format('Y-m-d');

            // Get completed order items for this day
            $orderItems = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.order_date', $dateStr)
                ->where('orders.order_status', 'paid')
                ->select('order_items.id as order_item_id', 'order_items.order_id', 'order_items.product_id', 'order_items.qty', 'order_items.created_at')
                ->get();

            foreach ($orderItems as $oi) {
                $ingredients = $productIngredients->get($oi->product_id, collect());
                foreach ($ingredients as $ing) {
                    $batch[] = [
                        'ingredient_id' => $ing->ingredient_id,
                        'order_id' => $oi->order_id,
                        'order_item_id' => $oi->order_item_id,
                        'type' => 'sale_deduction',
                        'quantity' => -($ing->quantity_required * $oi->qty),
                        'unit_cost' => null,
                        'reason' => 'Bahan terpakai order #'.$oi->order_id,
                        'idempotency_key' => Str::uuid()->toString(),
                        'created_by' => null,
                        'created_at' => $oi->created_at,
                    ];
                    $totalMov++;

                    if (count($batch) >= $batchSize) {
                        DB::table('ingredient_stock_movements')->insert($batch);
                        $batch = [];
                    }
                }
            }

            // Restock movements (~2 times per week)
            $dayOfWeek = $date->dayOfWeek;
            if ($dayOfWeek === Carbon::MONDAY || $dayOfWeek === Carbon::THURSDAY) {
                // Restock all active ingredients
                $ingredients = DB::table('ingredients')
                    ->where('is_active', 1)
                    ->select('id', 'cost_per_unit')
                    ->get();

                $restockTime = Carbon::parse("$dateStr 06:00:00", 'Asia/Jakarta')->setTimezone('UTC');
                $restockUser = $inventoryUserIds[array_rand($inventoryUserIds)];

                foreach ($ingredients as $ing) {
                    $restockQty = rand(500, 5000);
                    $batch[] = [
                        'ingredient_id' => $ing->id,
                        'order_id' => null,
                        'order_item_id' => null,
                        'type' => 'purchase_receipt',
                        'quantity' => $restockQty,
                        'unit_cost' => $ing->cost_per_unit,
                        'reason' => 'Restock rutin '.$date->format('d M Y'),
                        'idempotency_key' => Str::uuid()->toString(),
                        'created_by' => $restockUser,
                        'created_at' => $restockTime,
                    ];
                    $totalMov++;

                    if (count($batch) >= $batchSize) {
                        DB::table('ingredient_stock_movements')->insert($batch);
                        $batch = [];
                    }
                }
            }

            $date->addDay();
            $bar->advance();
        }

        // Flush remaining
        if (! empty($batch)) {
            DB::table('ingredient_stock_movements')->insert($batch);
        }

        // Recreate triggers
        DB::unprepared("
            CREATE TRIGGER trg_ingredient_stock_movements_no_delete BEFORE DELETE ON ingredient_stock_movements FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: DELETE dilarang.';
            END
        ");
        DB::unprepared("
            CREATE TRIGGER trg_ingredient_stock_movements_no_update BEFORE UPDATE ON ingredient_stock_movements FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: UPDATE dilarang.';
            END
        ");

        $bar->finish();
        $this->command->newLine();
        $this->command->info("  ✓ Ingredient stock movements seeded: {$totalMov}");
    }
}
