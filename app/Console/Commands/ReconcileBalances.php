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
                $products = Product::withoutTenantScope()->where('tenant_id', $tenant->id)->get();
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

                // --- Accounts Receivable (AR) Invariants Reconciliation ---
                if (DB::getSchemaBuilder()->hasTable('customer_invoices') && DB::getSchemaBuilder()->hasTable('customer_credit_accounts')) {
                    $creditAccounts = DB::table('customer_credit_accounts')
                        ->where('tenant_id', $tenant->id)
                        ->get();

                    foreach ($creditAccounts as $acc) {
                        $invoiceBalanceSum = DB::table('customer_invoices')
                            ->where('tenant_id', $tenant->id)
                            ->where('customer_id', $acc->customer_id)
                            ->whereIn('status', ['POSTED', 'PARTIALLY_PAID'])
                            ->sum('balance_due');

                        if (bccomp(bcadd((string) $acc->invoiced_exposure, '0', 2), bcadd((string) $invoiceBalanceSum, '0', 2), 2) !== 0) {
                            $this->error("AR Exposure Drift on Customer {$acc->customer_id}: InvoicedExposure = {$acc->invoiced_exposure}, OpenInvoiceBalanceSum = {$invoiceBalanceSum}");
                            $hasDrift = true;
                        }
                    }

                    $badInvoices = DB::table('customer_invoices')
                        ->where('tenant_id', $tenant->id)
                        ->whereRaw('ROUND(balance_due, 2) != ROUND(total_amount - paid_amount - credit_applied_amount, 2)')
                        ->count();

                    if ($badInvoices > 0) {
                        $this->error("Detected {$badInvoices} customer invoices with balance_due drift on Tenant {$tenant->id}");
                        $hasDrift = true;
                    }
                }

                // --- Sales Credit Note Invariants Reconciliation ---
                if (DB::getSchemaBuilder()->hasTable('sales_credit_notes')) {
                    $badCreditNotes = DB::table('sales_credit_notes')
                        ->where('tenant_id', $tenant->id)
                        ->whereRaw('ROUND(remaining_amount, 2) != ROUND(total_amount - applied_amount, 2)')
                        ->count();

                    if ($badCreditNotes > 0) {
                        $this->error("Detected {$badCreditNotes} sales credit notes with remaining_amount drift on Tenant {$tenant->id}");
                        $hasDrift = true;
                    }

                    if (DB::getSchemaBuilder()->hasTable('sales_credit_note_applications')) {
                        $creditNotesWithApps = DB::table('sales_credit_notes')
                            ->where('tenant_id', $tenant->id)
                            ->get();

                        foreach ($creditNotesWithApps as $cn) {
                            $sumApplied = DB::table('sales_credit_note_applications')
                                ->where('tenant_id', $tenant->id)
                                ->where('sales_credit_note_id', $cn->id)
                                ->sum('applied_amount');

                            if (bccomp(bcadd((string) $cn->applied_amount, '0', 2), bcadd((string) $sumApplied, '0', 2), 2) !== 0) {
                                $this->error("Credit Note #{$cn->credit_note_number} Applied Drift: recorded={$cn->applied_amount}, sum_applications={$sumApplied}");
                                $hasDrift = true;
                            }
                        }
                    }
                }

                // --- Gift Card Invariants Reconciliation ---
                if (DB::getSchemaBuilder()->hasTable('gift_cards') && DB::getSchemaBuilder()->hasTable('gift_card_ledgers')) {
                    $badGiftCards = DB::table('gift_cards')
                        ->where('tenant_id', $tenant->id)
                        ->whereRaw('current_balance < 0 OR current_balance > initial_balance')
                        ->count();

                    if ($badGiftCards > 0) {
                        $this->error("Detected {$badGiftCards} gift cards with invalid balance on Tenant {$tenant->id}");
                        $hasDrift = true;
                    }

                    $giftCards = DB::table('gift_cards')->where('tenant_id', $tenant->id)->get();
                    foreach ($giftCards as $gc) {
                        $ledgerSum = DB::table('gift_card_ledgers')
                            ->where('tenant_id', $tenant->id)
                            ->where('gift_card_id', $gc->id)
                            ->sum('amount');

                        if (bccomp(bcadd((string) $gc->current_balance, '0', 2), bcadd((string) $ledgerSum, '0', 2), 2) !== 0) {
                            $this->error("Gift Card #{$gc->card_number} Drift: recorded={$gc->current_balance}, sum_ledger={$ledgerSum}");
                            $hasDrift = true;
                        }
                    }
                }

                // --- Store Credit Invariants Reconciliation ---
                if (DB::getSchemaBuilder()->hasTable('store_credit_accounts') && DB::getSchemaBuilder()->hasTable('store_credit_ledgers')) {
                    $badStoreCredit = DB::table('store_credit_accounts')
                        ->where('tenant_id', $tenant->id)
                        ->where('current_balance', '<', 0)
                        ->count();

                    if ($badStoreCredit > 0) {
                        $this->error("Detected {$badStoreCredit} negative store credit accounts on Tenant {$tenant->id}");
                        $hasDrift = true;
                    }

                    $scAccounts = DB::table('store_credit_accounts')->where('tenant_id', $tenant->id)->get();
                    foreach ($scAccounts as $sca) {
                        $ledgerSum = DB::table('store_credit_ledgers')
                            ->where('tenant_id', $tenant->id)
                            ->where('store_credit_account_id', $sca->id)
                            ->sum('amount');

                        if (bccomp(bcadd((string) $sca->current_balance, '0', 2), bcadd((string) $ledgerSum, '0', 2), 2) !== 0) {
                            $this->error("Store Credit Account #{$sca->id} Drift: recorded={$sca->current_balance}, sum_ledger={$ledgerSum}");
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
