<?php

use App\Http\Controllers\Customer\CartController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\MenuController;
use App\Http\Controllers\Customer\WaiterCallController;
use Illuminate\Support\Facades\Route;

/**
 * Grup rute ini KHUSUS untuk pelanggan (customer self-order).
 * SENGAJA tidak memakai middleware 'auth' — pelanggan tidak perlu login sama sekali,
 * cukup scan QR code di meja lalu langsung memesan.
 *
 * PATCH FOR S-04: ganti throttle numerik bersama dengan limiter bernama (bucket TERPISAH per nama).
 */
Route::middleware(['throttle:customer-menu'])
    ->prefix('menu')
    ->name('customer.menu.')
    ->group(function () {
        Route::get('/{token}', [MenuController::class, 'index'])
            ->middleware('table.token')
            ->name('index');

        Route::get('/{product}/modifiers', [MenuController::class, 'modifiers'])->name('modifiers');
    });

Route::middleware(['throttle:customer-cart', 'table.session'])
    ->prefix('cart')
    ->name('customer.cart.')
    ->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('/', [CartController::class, 'store'])->name('store');
        Route::patch('/{lineId}', [CartController::class, 'update'])->name('update');
        Route::delete('/{lineId}', [CartController::class, 'destroy'])->name('destroy');
    });

Route::middleware(['throttle:customer-checkout', 'table.session'])
    ->prefix('checkout')
    ->name('customer.checkout.')
    ->group(function () {
        Route::get('/', [CheckoutController::class, 'create'])->name('create');
        Route::post('/', [CheckoutController::class, 'store'])->name('store');
        Route::get('/{orderCode}/success', [CheckoutController::class, 'success'])->name('success');
    });

// Polling status pesanan, dipisah agar bucket terpisah dari checkout.
Route::middleware(['throttle:customer-poll', 'table.session'])
    ->prefix('checkout')
    ->name('customer.checkout.')
    ->group(function () {
        Route::get('/{orderCode}/status', [CheckoutController::class, 'status'])->name('status');
    });

// PATCH FOR U-02/P-09: Panggil waiter yang nyata (bukan toast palsu).
Route::post('/waiter-call', [WaiterCallController::class, 'store'])
    ->middleware(['throttle:customer-cart', 'table.session'])
    ->name('customer.waiter.call');

// PATCH FOR U-03/P-19: Pemulihan keranjang (pesan ulang dari snapshot).
Route::post('/checkout/reorder', [CheckoutController::class, 'reorder'])
    ->middleware(['throttle:customer-checkout', 'table.session'])
    ->name('customer.checkout.reorder');
