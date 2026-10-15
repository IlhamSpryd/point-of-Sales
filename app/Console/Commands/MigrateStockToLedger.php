<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('inventory:migrate-stock-to-ledger')]
#[Description('Migrate legacy product.stock and ingredient.stock to stock_movements ledger')]
class MigrateStockToLedger extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Stock Ledger migration...');

        DB::beginTransaction();

        try {
            // Migrate Products
            $products = DB::table('products')->where('stock', '!=', 0)->get();
            $productMovements = [];
            $now = now();

            foreach ($products as $product) {
                $tenantId = $product->tenant_id;
                $storeId = $product->store_id ?? DB::table('stores')->where('tenant_id', $tenantId)->value('id');
                if (! $storeId) {
                    continue;
                }

                $productMovements[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'quantity' => $product->stock,
                    'type' => 'initial_sync',
                    'reason' => 'Migrated from legacy stock column',
                    'idempotency_key' => 'migration-prod-'.$product->id.'-'.$storeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($productMovements)) {
                DB::table('stock_movements')->insertOrIgnore($productMovements);
                $this->info('Successfully migrated '.count($productMovements).' product stocks.');
            } else {
                $this->info('No product stocks to migrate.');
            }

            // Migrate Ingredients
            $ingredients = DB::table('ingredients')->where('current_stock', '!=', 0)->get();
            $ingredientMovements = [];

            foreach ($ingredients as $ingredient) {
                $tenantId = $ingredient->tenant_id;
                $storeId = DB::table('stores')->where('tenant_id', $tenantId)->value('id');
                if (! $storeId) {
                    continue;
                }

                $ingredientMovements[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $ingredient->current_stock,
                    'type' => 'initial_sync',
                    'reason' => 'Migrated from legacy stock column',
                    'idempotency_key' => 'migration-ing-'.$ingredient->id.'-'.$storeId,
                    'created_at' => $now,
                ];
            }

            if (! empty($ingredientMovements)) {
                DB::table('ingredient_stock_movements')->insertOrIgnore($ingredientMovements);
                $this->info('Successfully migrated '.count($ingredientMovements).' ingredient stocks.');
            } else {
                $this->info('No ingredient stocks to migrate.');
            }

            DB::commit();
            $this->info('Stock ledger migration completed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Failed to migrate stock to ledger: '.$e->getMessage());
        }
    }
}
