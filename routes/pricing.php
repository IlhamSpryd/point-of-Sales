<?php

declare(strict_types=1);

use App\Http\Controllers\DiscountController;
use App\Livewire\Pricing\PromotionCampaignManager;
use App\Livewire\Pricing\VoucherManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pricing, Discounts & Promotions Routes (Modul 04 PRC — Pricing & Campaigns)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');
        Route::get('/pricing/promotions', PromotionCampaignManager::class)->name('pricing.promotions.index');
        Route::get('/pricing/vouchers', VoucherManager::class)->name('pricing.vouchers.index');
    });
});
