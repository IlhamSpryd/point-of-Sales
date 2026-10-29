<?php

declare(strict_types=1);

use App\Http\Controllers\FinanceDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Finance & Accounting Routes (Modul 7 — General Ledger & Financials)
|--------------------------------------------------------------------------
|
| Hub akuntansi dual-entry, buku besar, neraca saldo, dan rekonsiliasi.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager,Finance,Admin')->group(function () {
        Route::get('/finance', [FinanceDashboardController::class, 'index'])->name('finance.index');
    });
});
