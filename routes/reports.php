<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportTaskController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports & Analytics Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    // Dashboard & Laporan Penjualan
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/reports/sales', [ReportController::class, 'index'])->name('reports.sales');
        Route::post('/reports/sales/eod-close', [ReportController::class, 'closeEod'])->name('reports.eod-close');
    });

    // Ekspor Laporan Asinkron
    Route::middleware(['role:Owner,Manager', 'enforce.entitlement:async_export'])->group(function () {
        Route::get('/exports/{exportTask}/status', [ExportTaskController::class, 'status'])->name('exports.status');
        Route::get('/exports/{exportTask}/download', [ExportTaskController::class, 'download'])->name('exports.download');
    });
});
