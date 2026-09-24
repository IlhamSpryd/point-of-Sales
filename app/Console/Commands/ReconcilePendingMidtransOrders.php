<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\TransactionService;
use Illuminate\Console\Command;
use Midtrans\Config;
use Midtrans\Transaction;

class ReconcilePendingMidtransOrders extends Command
{
    protected $signature = 'orders:reconcile-midtrans';

    protected $description = 'Rekonsiliasi status pesanan Midtrans yang pending (stale) untuk mencegah stock leak.';

    public function handle(TransactionService $service): int
    {
        $stale = Order::where('order_status', OrderStatus::Pending)
            ->where('payment_method', '!=', 'cash')
            ->whereNotNull('snap_token')
            ->where('created_at', '<=', now()->subMinutes(30))
            ->get();

        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production', false);

        foreach ($stale as $order) {
            try {
                $status = Transaction::status($order->order_code);

                $service->updateStatusFromMidtransNotification(
                    orderCode: $order->order_code,
                    transactionStatus: $status->transaction_status,
                    fraudStatus: $status->fraud_status ?? null,
                    grossAmount: (int) $status->gross_amount,
                );
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), '404')) {
                    // Order never created in midtrans (e.g. user abandoned snap before it fully loaded)
                    $service->updateStatusFromMidtransNotification(
                        orderCode: $order->order_code,
                        transactionStatus: 'expire',
                        fraudStatus: null,
                        grossAmount: (int) $order->order_amount,
                    );
                }
                $this->error("Failed to reconcile {$order->order_code}: {$e->getMessage()}");
            }
        }

        $this->info("{$stale->count()} Midtrans orders direkonsiliasi.");

        return self::SUCCESS;
    }
}
