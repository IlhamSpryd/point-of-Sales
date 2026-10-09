<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileBalances extends Command
{
    protected $signature = 'inventory:reconcile-balances {--tenant=}';

    protected $description = 'Reports drift between legacy column, balance row, and baseline-adjusted ledger.';

    public function handle()
    {
        $tenantId = $this->option('tenant');
        $tenants = $tenantId ? Tenant::where('id', $tenantId)->get() : Tenant::all();
        $hasDrift = false;

        $this->info('Starting reconciliation...');

        foreach ($tenants as $tenant) {
            app(TenantContext::class)->runAs($tenant->id, null, function () use ($tenant, &$hasDrift) {
                $stores = Store::where('tenant_id', $tenant->id)->get();
                if ($stores->isEmpty()) {
                    return;
                }

                // --- Products ---
                $products = Product::where('tenant_id', $tenant->id)->get();
                foreach ($products as $product) {
                    // (a) legacy column
                    $legacyStock = $product->stock;

                    // (b) balance row sum (across all stores)
                    $balanceSum = DB::table('product_stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('product_id', $product->id)
                        ->sum('quantity');

                    if (round((float) $legacyStock, 4) !== round((float) $balanceSum, 4)) {
                        $this->error("Drift on Product {$product->id}: Legacy = {$legacyStock}, BalanceRowSum = {$balanceSum}");
                        $hasDrift = true;
                    }

                    // (c) baseline-adjusted ledger per store
                    foreach ($stores as $store) {
                        $balanceRow = DB::table('product_stock_balances')
                            ->where('tenant_id', $tenant->id)
                            ->where('store_id', $store->id)
                            ->where('product_id', $product->id)
                            ->first();

                        if (! $balanceRow) {
                            $this->error("Missing product_stock_balances for Tenant {$tenant->id}, Store {$store->id}, Product {$product->id}");
                            $hasDrift = true;

                            continue;
                        }

                        $ledgerSum = DB::table('stock_movements')
                            ->where('tenant_id', $tenant->id)
                            ->where('store_id', $store->id)
                            ->where('product_id', $product->id)
                            ->where('id', '>', $balanceRow->baseline_movement_id)
                            ->sum('quantity') ?? 0;

                        $calculatedBalance = $balanceRow->baseline_quantity + $ledgerSum;

                        if (round((float) $balanceRow->quantity, 4) !== round((float) $calculatedBalance, 4)) {
                            $this->error("Drift on Product {$product->id}, Store {$store->id}: BalanceRow = {$balanceRow->quantity}, Calculated = {$calculatedBalance} (Baseline: {$balanceRow->baseline_quantity}, LedgerSum: {$ledgerSum})");
                            $hasDrift = true;
                        }
                    }
                }

                // --- Ingredients ---
                $ingredients = Ingredient::where('tenant_id', $tenant->id)->get();
                foreach ($ingredients as $ingredient) {
                    // (a) legacy column
                    $legacyStock = $ingredient->current_stock;

                    // (b) balance row sum
                    $balanceSum = DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('ingredient_id', $ingredient->id)
                        ->sum('quantity');

                    if (round((float) $legacyStock, 4) !== round((float) $balanceSum, 4)) {
                        $this->error("Drift on Ingredient {$ingredient->id}: Legacy = {$legacyStock}, BalanceRowSum = {$balanceSum}");
                        $hasDrift = true;
                    }

                    // (c) baseline-adjusted ledger per store
                    foreach ($stores as $store) {
                        $balanceRow = DB::table('ingredient_stock_balances')
                            ->where('tenant_id', $tenant->id)
                            ->where('store_id', $store->id)
                            ->where('ingredient_id', $ingredient->id)
                            ->first();

                        if (! $balanceRow) {
                            $this->error("Missing ingredient_stock_balances for Tenant {$tenant->id}, Store {$store->id}, Ingredient {$ingredient->id}");
                            $hasDrift = true;

                            continue;
                        }

                        $ledgerSum = DB::table('ingredient_stock_movements')
                            ->where('tenant_id', $tenant->id)
                            ->where('store_id', $store->id)
                            ->where('ingredient_id', $ingredient->id)
                            ->where('id', '>', $balanceRow->baseline_movement_id)
                            ->sum('quantity') ?? 0;

                        $calculatedBalance = $balanceRow->baseline_quantity + $ledgerSum;

                        if (round((float) $balanceRow->quantity, 4) !== round((float) $calculatedBalance, 4)) {
                            $this->error("Drift on Ingredient {$ingredient->id}, Store {$store->id}: BalanceRow = {$balanceRow->quantity}, Calculated = {$calculatedBalance}");
                            $hasDrift = true;
                        }
                    }
                }
            });
        }

        if ($hasDrift) {
            $this->error('Reconciliation failed. Drift detected.');

            return 1;
        }

        $this->info('Reconciliation successful. No drift detected.');

        return 0;
    }
}
