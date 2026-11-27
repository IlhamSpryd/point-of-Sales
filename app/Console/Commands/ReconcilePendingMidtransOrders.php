<?php

namespace App\Console\Commands;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Modules\Integration\Contracts\PaymentGatewayInterface;
use App\Modules\Organization\Models\Setting;
use App\Modules\Sales\Application\Handlers\OnlinePaymentAndWebhookHandler;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Services\TransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Midtrans\Config;

class ReconcilePendingMidtransOrders extends Command
{
    protected $signature = 'orders:reconcile-midtrans';

    protected $description = 'Rekonsiliasi status pesanan Midtrans yang pending (stale) menggunakan PaymentAttempt provider_order_id dan kredensial per-tenant.';

    public function handle(
        TransactionService $service,
        OnlinePaymentAndWebhookHandler $onlineHandler,
        PaymentGatewayInterface $paymentGateway,
        TenantContext $tenantContext,
    ): int {
        // @tenant-bypass: cross_tenant_reconciliation
        $stale = Order::withoutGlobalScopes()
            ->where('order_status', OrderStatus::Pending)
            ->where('payment_method', '!=', 'cash')
            ->where('created_at', '<=', now()->subMinutes(30))
            ->where(function ($query) {
                $query->whereNotNull('snap_token')
                    ->orWhereHas('paymentAttempts', function ($attemptQuery) {
                        $attemptQuery->where('provider', 'midtrans')
                            ->whereIn('status', [
                                PaymentAttemptStatus::Initiated->value,
                                PaymentAttemptStatus::PendingProvider->value,
                                PaymentAttemptStatus::UnknownTimeout->value,
                            ]);
                    });
            })
            ->get();

        $reconciledCount = 0;

        foreach ($stale as $order) {
            $tenantContext->runAs((int) $order->tenant_id, $order->store_id !== null ? (int) $order->store_id : null, function () use ($order, $service, $onlineHandler, $paymentGateway, &$reconciledCount) {
                $tenantKey = Setting::get('midtrans_server_key', '');
                $serverKey = is_string($tenantKey) && $tenantKey !== '' ? $tenantKey : (string) config('services.midtrans.server_key', '');
                $tenantProd = Setting::get('midtrans_is_production', null);
                $isProduction = $tenantProd !== null
                    ? filter_var($tenantProd, FILTER_VALIDATE_BOOLEAN)
                    : (bool) config('services.midtrans.is_production', false);

                Config::$serverKey = $serverKey;
                Config::$isProduction = $isProduction;

                $attempts = PaymentAttempt::where('order_id', $order->id)
                    ->where('provider', 'midtrans')
                    ->whereIn('status', [
                        PaymentAttemptStatus::Initiated->value,
                        PaymentAttemptStatus::PendingProvider->value,
                        PaymentAttemptStatus::UnknownTimeout->value,
                    ])
                    ->orderByDesc('id')
                    ->get();

                $targets = $attempts->isNotEmpty() ? $attempts->all() : [null];

                foreach ($targets as $attempt) {
                    $providerOrderId = $attempt instanceof PaymentAttempt
                        ? (string) $attempt->provider_order_id
                        : (string) $order->order_code;

                    try {
                        $status = $paymentGateway->getTransactionStatus($providerOrderId);
                        $transactionStatus = (string) ($status->transaction_status ?? '');

                        if ($transactionStatus !== '') {
                            $onlineHandler->handleMidtransWebhook([
                                'order_id' => $providerOrderId,
                                'transaction_status' => $transactionStatus,
                                'fraud_status' => $status->fraud_status ?? null,
                                'transaction_id' => $status->transaction_id ?? null,
                                'gross_amount' => $status->gross_amount ?? (string) ($attempt instanceof PaymentAttempt ? $attempt->amount : $order->order_amount),
                            ]);
                            $reconciledCount++;

                            if (in_array($transactionStatus, ['settlement', 'capture'], true)) {
                                break;
                            }
                        }
                    } catch (\Throwable $e) {
                        $hasSucceededOrOtherPending = PaymentAttempt::where('order_id', $order->id)
                            ->where('provider', 'midtrans')
                            ->when($attempt instanceof PaymentAttempt, fn ($q) => $q->where('id', '!=', $attempt->id))
                            ->whereIn('status', [
                                PaymentAttemptStatus::Succeeded->value,
                                PaymentAttemptStatus::Initiated->value,
                                PaymentAttemptStatus::PendingProvider->value,
                                PaymentAttemptStatus::UnknownTimeout->value,
                            ])
                            ->exists();

                        if (str_contains($e->getMessage(), '404') && $order->created_at <= now()->subHours(2) && ! $hasSucceededOrOtherPending) {
                            $service->updateStatusFromMidtransNotification(
                                orderCode: $order->order_code,
                                transactionStatus: 'expire',
                                fraudStatus: null,
                                grossAmount: (int) $order->order_amount,
                            );
                            $reconciledCount++;
                        } else {
                            Log::warning('[RECONCILE-MIDTRANS] Skip/Error for provider order '.$providerOrderId.': '.$e->getMessage());
                        }
                    }
                }
            });
        }

        $this->info("{$reconciledCount} Midtrans orders direkonsiliasi.");

        return self::SUCCESS;
    }
}
