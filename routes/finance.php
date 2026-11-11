<?php

declare(strict_types=1);

use App\Http\Controllers\FinanceDashboardController;
use App\Livewire\Finance\AccountingPeriodManager;
use App\Livewire\Finance\BankReconciliationWorkbench;
use App\Livewire\Finance\ChartOfAccountManager;
use App\Livewire\Finance\FixedAssetManager;
use App\Livewire\Finance\JournalEntryManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Finance & Accounting Routes (Modul 12 FIN — General Ledger & Financials)
|--------------------------------------------------------------------------
|
| Hub akuntansi dual-entry, buku besar, neraca saldo, dan rekonsiliasi.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager,Finance,Admin')->group(function () {
        Route::get('/finance', [FinanceDashboardController::class, 'index'])->name('finance.index');
        Route::get('/finance/chart-of-accounts', ChartOfAccountManager::class)->name('finance.coa.index');
        Route::get('/finance/journal-entries', JournalEntryManager::class)->name('finance.journals.index');
        Route::get('/finance/accounting-periods', AccountingPeriodManager::class)->name('finance.periods.index');
        Route::get('/finance/bank-reconciliation', BankReconciliationWorkbench::class)->name('finance.reconciliation.index');
        Route::get('/finance/fixed-assets', FixedAssetManager::class)->name('finance.fixed-assets.index');
    });
});
