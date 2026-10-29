<?php

declare(strict_types=1);

use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\QzTraySigningController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TransactionController;
use App\Livewire\ChannelMappingManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kds\Board;
use App\Livewire\Sales\AccountsReceivableManager;
use App\Livewire\Sales\QuotationManager;
use App\Livewire\Sales\ReturnCreditNoteManager;
use App\Livewire\Sales\SalesOrderManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales & Cashier Routes
|--------------------------------------------------------------------------
*/

// Webhook Midtrans (server-to-server, TANPA CSRF & TANPA auth)
Route::post('/midtrans/notification', [MidtransNotificationController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('midtrans.notification');

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    // Kasir POS
    Route::middleware('role:Owner,Manager,Kasir')->group(function () {
        Route::get('/transaction', CreateOrder::class)->name('transaction.create');
        Route::post('/transaction', [TransactionController::class, 'store'])->name('transaction.store');
        Route::get('/transaction/receipt/{orderCode}', [TransactionController::class, 'receipt'])->name('transaction.receipt');
        Route::post('/api/orders/{order_number}/sync-status', [TransactionController::class, 'syncMidtrans'])->name('api.order.sync-status');
        Route::get('/api/orders/{order_number}/print-payload', [TransactionController::class, 'printPayload'])->name('api.order.print-payload');
        Route::get('/api/orders/{order_number}/print-kitchen-payload', [TransactionController::class, 'printKitchenPayload'])->name('api.order.print-kitchen-payload');

        Route::get('/qz/certificate', [QzTraySigningController::class, 'certificate'])->name('qz.certificate');
        Route::post('/qz/sign', [QzTraySigningController::class, 'sign'])->name('qz.sign');
    });

    // Dapur (KDS)
    Route::middleware('role:Owner,Manager,Kasir,Barista,Waiter,Cook')->group(function () {
        Route::get('/kds', Board::class)->name('kds.index');
    });

    // Shift Kasir
    Route::middleware('role:Owner,Manager,Kasir,Supervisor')->group(function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
        Route::post('/shifts/{shift}/movement', [ShiftController::class, 'recordCashMovement'])->name('shifts.movement');
        Route::post('/shifts/{shift}/handover', [ShiftController::class, 'initiateHandover'])->name('shifts.handover');
        Route::post('/shifts/handover/{handoverId}/accept', [ShiftController::class, 'acceptHandover'])->name('shifts.handover.accept');
        Route::post('/shifts/handover/{handoverId}/cancel', [ShiftController::class, 'cancelHandover'])->name('shifts.handover.cancel');
        Route::post('/shifts/{shift}/force-close', [ShiftController::class, 'forceClose'])->name('shifts.force-close');
        Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
    });

    // Riwayat Pesanan
    Route::middleware('role:Owner,Manager,Kasir,Waiter')->group(function () {
        Route::get('/orders', [TransactionController::class, 'index'])->name('orders.index');
        Route::get('/orders/{orderNumber}', [TransactionController::class, 'show'])->name('orders.show');
        Route::post('/orders/{orderNumber}/void', [TransactionController::class, 'void'])->name('orders.void');
        Route::post('/orders/{orderNumber}/return', [TransactionController::class, 'returnAndRefund'])->name('orders.return');
    });

    // Channel Mapping Manager (Omnichannel)
    Route::middleware(['role:Owner,Manager', 'enforce.entitlement:omnichannel'])->group(function () {
        Route::get('/integrations/channel-mapping', ChannelMappingManager::class)->name('integrations.channel-mapping');
    });

    // B2B Sales, Quotations, Sales Orders & Accounts Receivable (AR)
    Route::middleware('role:Owner,Manager,Sales,Admin,Finance')->group(function () {
        Route::get('/sales/quotations', QuotationManager::class)->name('sales.quotations.index');
        Route::get('/sales/orders', SalesOrderManager::class)->name('sales.orders.index');
        Route::get('/sales/invoices', AccountsReceivableManager::class)->name('sales.invoices.index');
        Route::get('/sales/returns', ReturnCreditNoteManager::class)->name('sales.returns.index');
    });
});
