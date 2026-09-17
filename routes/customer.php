<?php

use App\Http\Controllers\Customer\MenuController;
use Illuminate\Support\Facades\Route;

/**
 * Grup rute ini KHUSUS untuk pelanggan (customer self-order).
 * SENGAJA tidak memakai middleware 'auth' — pelanggan tidak perlu login sama sekali,
 * cukup scan QR code di meja lalu langsung memesan.
 *
 * throttle:60,1 = pembatasan 60 request per menit per IP, sebagai pengaman dasar
 * karena rute ini terbuka untuk publik (rawan disalahgunakan bot/spam jika tanpa batas).
 */
Route::middleware(['throttle:60,1'])
    ->prefix('menu')
    ->name('customer.menu.')
    ->group(function () {
        // PERUBAHAN: sekarang WAJIB membawa {token} dari hasil scan QR Code meja.
        Route::get('/{token}', [MenuController::class, 'index'])
            ->middleware('table.token')
            ->name('index');

        // Endpoint JSON: mengambil daftar grup varian + opsi untuk SATU produk,
        // dipanggil via fetch() oleh Alpine.js saat pelanggan klik kartu produk.
        Route::get('/{product}/modifiers', [MenuController::class, 'modifiers'])->name('modifiers');
    });

use App\Http\Controllers\Customer\CartController;

Route::middleware(['throttle:60,1', 'table.session'])
    ->prefix('cart')
    ->name('customer.cart.')
    ->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('/', [CartController::class, 'store'])->name('store');
        Route::patch('/{lineId}', [CartController::class, 'update'])->name('update');
        Route::delete('/{lineId}', [CartController::class, 'destroy'])->name('destroy');
    });

use App\Http\Controllers\Customer\CheckoutController;

Route::middleware(['throttle:5,1', 'table.session'])  // Hanya 5 request per menit untuk checkout
    ->prefix('checkout')
    ->name('customer.checkout.')
    ->group(function () {
        Route::get('/', [CheckoutController::class, 'create'])->name('create');
        Route::post('/', [CheckoutController::class, 'store'])->name('store');
        Route::get('/{orderCode}/success', [CheckoutController::class, 'success'])->name('success');
    });
