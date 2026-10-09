<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillBalances extends Command
{
    protected $signature = 'inventory:backfill-balances {--tenant=}';

    protected $description = 'Backfill stock balances for products and ingredients';

    public function handle()
    {
        $tenantId = $this->option('tenant');

        $tenants = $tenantId ? Tenant::where('id', $tenantId)->get() : Tenant::all();

        foreach ($tenants as $tenant) {
            app(TenantContext::class)->runAs($tenant->id, null, function () use ($tenant) {
                $stores = Store::where('tenant_id', $tenant->id)->orderBy('id')->get();
                if ($stores->isEmpty()) {
                    return;
                }
                $defaultStore = $stores->first();

                // Products
                $products = Product::where('tenant_id', $tenant->id)->get();
                $maxProductMovementId = DB::table('stock_movements')->where('tenant_id', $tenant->id)->max('id') ?? 0;

                foreach ($products as $product) {
                    foreach ($stores as $store) {
                        $qty = $store->id === $defaultStore->id ? $product->stock : 0;
                        DB::table('product_stock_balances')->updateOrInsert(
                            ['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id],
                            [
                                'quantity' => $qty,
                                'baseline_quantity' => $qty,
                                'baseline_movement_id' => $maxProductMovementId,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                }

                // Ingredients
                $ingredients = Ingredient::where('tenant_id', $tenant->id)->get();
                $maxIngredientMovementId = DB::table('ingredient_stock_movements')->where('tenant_id', $tenant->id)->max('id') ?? 0;

                foreach ($ingredients as $ingredient) {
                    foreach ($stores as $store) {
                        $qty = $store->id === $defaultStore->id ? $ingredient->current_stock : 0;
                        DB::table('ingredient_stock_balances')->updateOrInsert(
                            ['tenant_id' => $tenant->id, 'store_id' => $store->id, 'ingredient_id' => $ingredient->id],
                            [
                                'quantity' => $qty,
                                'baseline_quantity' => $qty,
                                'baseline_movement_id' => $maxIngredientMovementId,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                }
            });

            $this->info("Backfilled balances for Tenant ID: {$tenant->id}");
        }

        $this->info('Backfill completed.');

        return 0;
    }
}
