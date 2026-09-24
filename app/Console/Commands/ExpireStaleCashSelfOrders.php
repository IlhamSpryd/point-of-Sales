<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\TransactionService;
use Illuminate\Console\Command;

class ExpireStaleCashSelfOrders extends Command
{
    protected $signature = 'orders:expire-stale-cash';

    protected $description = 'Kedaluwarsakan self-order cash yang tidak dikonfirmasi kasir dalam batas waktu, kembalikan stok.';

    public function handle(TransactionService $service): int
    {
        // [OMEGA-NODE9] SEC FIX HIGH: sebelumnya hanya menyapu order
        // self-order CASH. Order QRIS/E-wallet yang macet karena Snap
        // gagal terbuat (snap_token tetap NULL) sebelumnya tidak PERNAH
        // disapu, membuat stok/BOM menggantung permanen. | 2026-09-25
        $staleSelfOrderCash = Order::where('order_status', OrderStatus::Pending)
            ->where('payment_method', 'cash')
            ->whereNotNull('table_id')
            ->where('created_at', '<=', now()->subMinutes(30));

        $staleNonCashStuck = Order::where('order_status', OrderStatus::Pending)
            ->whereIn('payment_method', ['qris', 'ewallet'])
            ->whereNull('snap_token')
            ->where('created_at', '<=', now()->subMinutes(35));

        // Use union then get
        $stale = $staleSelfOrderCash->union($staleNonCashStuck)->get();

        foreach ($stale as $order) {
            $service->updateStatusFromMidtransNotification(
                orderCode: $order->order_code,
                transactionStatus: 'expire',
                fraudStatus: null,
                grossAmount: (int) $order->order_amount,
            );
        }

        $this->info("{$stale->count()} self-order cash kedaluwarsa diproses.");

        return self::SUCCESS;
    }
}
