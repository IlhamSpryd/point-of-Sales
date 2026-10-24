<?php

declare(strict_types=1);

use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StoreProductAssortmentController;
use App\Http\Controllers\TaxClassController;
use App\Http\Controllers\UnitOfMeasureController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Products & Catalog Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::post('products/import', [ProductController::class, 'import'])->name('products.import');
        Route::post('products/bulk-update', [ProductController::class, 'bulkUpdate'])->name('products.bulk-update');
        Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
        Route::delete('products/{product}/media/{media}', [ProductController::class, 'destroyMedia'])->name('products.media.destroy');
        Route::post('products/{product}/media/{media}/set-primary', [ProductController::class, 'setPrimaryMedia'])->name('products.media.set-primary');
        Route::resource('products', ProductController::class);

        Route::get('categories/export', [CategoryController::class, 'export'])->name('categories.export');
        Route::resource('categories', CategoryController::class);

        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('units-of-measure', UnitOfMeasureController::class)->except(['show']);
        Route::resource('tax-classes', TaxClassController::class)->except(['show']);
        Route::resource('price-lists', PriceListController::class);

        Route::get('assortments', [StoreProductAssortmentController::class, 'index'])->name('assortments.index');
        Route::post('assortments', [StoreProductAssortmentController::class, 'update'])->name('assortments.update');
    });
});
