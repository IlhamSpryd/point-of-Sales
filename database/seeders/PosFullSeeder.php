<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PosFullSeeder: Orchestrator seeder for the full Yovel Coffee & Cafe POS dataset.
 *
 * Generates a realistic 12-month dataset with ~50,000 orders, 35 employees,
 * 133 menu items, 1000 customers, and all supporting transactional data.
 *
 * Usage: php artisan db:seed --class=PosFullSeeder
 * Or:    php artisan migrate:fresh --seed  (calls DatabaseSeeder which calls this)
 *
 * Target execution time: 10-15 minutes depending on hardware.
 */
class PosFullSeeder extends Seeder
{
    public function run(): void
    {
        $startTime = microtime(true);

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════╗');
        $this->command->info('║   🏪 Yovel Coffee & Cafe — Full POS Data Seeder     ║');
        $this->command->info('║   Target: 12 months, ~50K orders, 133 menu items    ║');
        $this->command->info('╚══════════════════════════════════════════════════════╝');
        $this->command->info('');

        // Disable FK checks for truncation safety during master data seeding
        Schema::disableForeignKeyConstraints();

        // ══════════════════════════════════════════════════════
        // PHASE 1: Master Data (fast — under 30 seconds)
        // ══════════════════════════════════════════════════════
        $this->command->info('━━━ Phase 1: Master Data ━━━');

        $this->call(Pos\RoleSeeder::class);
        $this->call(Pos\UserSeeder::class);
        $this->call(Pos\CategorySeeder::class);
        $this->call(Pos\ProductSeeder::class);
        $this->call(Pos\IngredientSeeder::class);
        $this->call(Pos\ProductIngredientSeeder::class);

        // ══════════════════════════════════════════════════════
        // PHASE 2: Modifiers & Venue Setup
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Phase 2: Modifiers & Venue ━━━');

        $this->call(Pos\ModifierSeeder::class);
        $this->call(Pos\ModifierGroupProductSeeder::class);
        $this->call(Pos\ModifierIngredientSeeder::class);
        $this->call(Pos\TableSeeder::class);

        // ══════════════════════════════════════════════════════
        // PHASE 3: Customer & Loyalty Setup
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Phase 3: Customers & Loyalty ━━━');

        $this->call(Pos\LoyaltyTierSeeder::class);
        $this->call(Pos\CustomerSeeder::class);
        $this->call(Pos\DiscountSeeder::class);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ══════════════════════════════════════════════════════
        // PHASE 4: Transactional Data (heavy — several minutes)
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Phase 4: Shifts ━━━');
        $this->call(Pos\ShiftSeeder::class);

        $this->command->info('');
        $this->command->info('━━━ Phase 5: Orders + Items + Modifiers + Payments + Stock Movements ━━━');
        $this->command->info('  (This is the heaviest phase — generating ~50K orders...)');
        $this->call(Pos\OrderSeeder::class);

        // ══════════════════════════════════════════════════════
        // PHASE 6: Ingredient Stock Movements (very heavy)
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Phase 6: Ingredient Stock Movements ━━━');
        $this->command->info('  (Processing ingredient usage for all completed orders...)');
        $this->call(Pos\IngredientStockMovementSeeder::class);

        // ══════════════════════════════════════════════════════
        // PHASE 7: Loyalty Ledger & Accounts
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Phase 7: Loyalty Ledger ━━━');
        $this->call(Pos\LoyaltyLedgerSeeder::class);

        // ══════════════════════════════════════════════════════
        // PHASE 8: Cash Drawer & Activity Logs
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Phase 8: Cash Drawer & Activity Logs ━━━');
        $this->call(Pos\CashDrawerMovementSeeder::class);
        $this->call(Pos\ActivityLogSeeder::class);

        // ══════════════════════════════════════════════════════
        // VALIDATION: Summary statistics
        // ══════════════════════════════════════════════════════
        $this->command->info('');
        $this->command->info('━━━ Validation Summary ━━━');
        $this->printSummary();

        $elapsed = round((microtime(true) - $startTime) / 60, 1);
        $this->command->info('');
        $this->command->info("✅ Seeding complete in {$elapsed} minutes.");
        $this->command->info('');
    }

    private function printSummary(): void
    {
        // Table counts
        $counts = [
            'users' => DB::table('users')->count(),
            'categories' => DB::table('categories')->count(),
            'products' => DB::table('products')->count(),
            'ingredients' => DB::table('ingredients')->count(),
            'product_ingredients' => DB::table('product_ingredients')->count(),
            'modifier_groups' => DB::table('modifier_groups')->count(),
            'modifiers' => DB::table('modifiers')->count(),
            'tables' => DB::table('tables')->count(),
            'customers' => DB::table('customers')->count(),
            'loyalty_tiers' => DB::table('loyalty_tiers')->count(),
            'discounts' => DB::table('discounts')->count(),
            'shifts' => DB::table('shifts')->count(),
            'orders' => DB::table('orders')->count(),
            'order_items' => DB::table('order_items')->count(),
            'order_item_modifiers' => DB::table('order_item_modifiers')->count(),
            'payments' => DB::table('payments')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'ingredient_stock_movements' => DB::table('ingredient_stock_movements')->count(),
            'loyalty_ledger' => DB::table('loyalty_ledger')->count(),
            'customer_loyalty_accounts' => DB::table('customer_loyalty_accounts')->count(),
            'cash_drawer_movements' => DB::table('cash_drawer_movements')->count(),
            'activity_logs' => DB::table('activity_logs')->count(),
        ];

        $this->command->table(
            ['Table', 'Row Count'],
            collect($counts)->map(fn ($count, $table) => [$table, number_format($count)])->toArray()
        );

        // Monthly order stats
        $this->command->info('');
        $this->command->info('  📊 Monthly Order Summary:');
        $monthlyStats = DB::table('orders')
            ->selectRaw("DATE_FORMAT(order_date, '%Y-%m') as month, COUNT(*) as orders, SUM(order_amount) as revenue")
            ->where('order_status', 'completed')
            ->groupByRaw("DATE_FORMAT(order_date, '%Y-%m')")
            ->orderBy('month')
            ->get();

        foreach ($monthlyStats as $stat) {
            $this->command->info(sprintf(
                '    %s: %s orders, Revenue Rp %s',
                $stat->month,
                number_format($stat->orders),
                number_format($stat->revenue)
            ));
        }

        // Top 10 products
        $this->command->info('');
        $this->command->info('  🏆 Top 10 Best Sellers:');
        $topProducts = DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.order_status', 'completed')
            ->selectRaw('products.product_name, SUM(order_items.qty) as total_sold, SUM(order_items.order_subtotal) as revenue')
            ->groupBy('products.product_name')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        foreach ($topProducts as $i => $p) {
            $this->command->info(sprintf(
                '    %2d. %-35s %s sold (Rp %s)',
                $i + 1,
                $p->product_name,
                number_format($p->total_sold),
                number_format($p->revenue)
            ));
        }

        // Total revenue
        $totalRevenue = DB::table('orders')
            ->where('order_status', 'completed')
            ->sum('order_amount');
        $this->command->info('');
        $this->command->info('  💰 Total Revenue (12 months): Rp '.number_format($totalRevenue));
    }
}
