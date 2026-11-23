<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportTaskController;
use App\Http\Controllers\ReportController;
use App\Livewire\Reports\AdvancedAnalyticsDashboard;
use App\Modules\Reporting\Controllers\DashboardChartApiController;
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
        Route::post('/dashboard/approve-ticket', [DashboardController::class, 'approveTicket'])->name('dashboard.approve-ticket');
        Route::post('/dashboard/store-targets', [DashboardController::class, 'saveStoreTarget'])->name('dashboard.store-targets.save');
        Route::get('/dashboard/orders/{orderId}/receipt', [DashboardController::class, 'orderReceipt'])->name('dashboard.orders.receipt');
        Route::get('/dashboard/products/{productId}/recipe-bom', [DashboardController::class, 'productRecipeBom'])->name('dashboard.products.recipe-bom');

        // Reactive ECharts REST API & Drilldown Endpoints
        Route::prefix('dashboard/api')->name('dashboard.api.')->group(function () {
            Route::get('/revenue-series', [DashboardChartApiController::class, 'revenueSeries'])->name('revenue-series');
            Route::get('/payment-volume', [DashboardChartApiController::class, 'paymentVolume'])->name('payment-volume');
            Route::get('/profitability-combo', [DashboardChartApiController::class, 'profitabilityCombo'])->name('profitability-combo');
            Route::get('/waterfall-bridge', [DashboardChartApiController::class, 'waterfallBridge'])->name('waterfall-bridge');
            Route::get('/demand-heatmap', [DashboardChartApiController::class, 'demandHeatmap'])->name('demand-heatmap');
            Route::get('/drilldown/date', [DashboardChartApiController::class, 'dateSalesDrilldown'])->name('drilldown.date');
            Route::get('/drilldown/hourly', [DashboardChartApiController::class, 'hourlyOrdersDrilldown'])->name('drilldown.hourly');
            Route::get('/drilldown/payment-method', [DashboardChartApiController::class, 'paymentMethodDrilldown'])->name('drilldown.payment-method');
            Route::get('/drilldown/waterfall-step', [DashboardChartApiController::class, 'waterfallStepDrilldown'])->name('drilldown.waterfall-step');
        });

        Route::get('/analytics', AdvancedAnalyticsDashboard::class)->name('reports.analytics');
        Route::get('/reports/sales', [ReportController::class, 'index'])->name('reports.sales');
        Route::post('/reports/sales/eod-close', [ReportController::class, 'closeEod'])->name('reports.eod-close');
    });

    // Ekspor Laporan Asinkron
    Route::middleware(['role:Owner,Manager', 'enforce.entitlement:async_export'])->group(function () {
        Route::get('/exports/{exportTask}/status', [ExportTaskController::class, 'status'])->name('exports.status');
        Route::get('/exports/{exportTask}/download', [ExportTaskController::class, 'download'])->name('exports.download');
    });
});
