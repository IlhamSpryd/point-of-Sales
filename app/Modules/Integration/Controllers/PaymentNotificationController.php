<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Modules\Integration\Services\PaymentWebhookVerificationService;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentNotificationController extends Controller
{
    public function __construct(
        protected PaymentWebhookVerificationService $verificationService,
        protected TransactionService $transactionService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');

        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '') {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        // Verifikasi tanda tangan digital SHA-512 via PaymentWebhookVerificationService
        // Menolak tanda tangan palsu dengan HTTP 401 Unauthorized
        $this->verificationService->assertValid($orderId, $statusCode, $grossAmount, $signatureKey);

        // Cari transaksi pesanan
        $attempt = PaymentAttempt::withoutGlobalScopes()->where('provider_order_id', $orderId)->first();
        $order = $attempt
            ? Order::withoutGlobalScopes()->where('id', $attempt->order_id)->first()
            : Order::withoutGlobalScopes()->where('order_code', $orderId)->first();

        if (! $order || ! $order->tenant_id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $tenantContext = app(TenantContext::class);
        $prevTenantId = $tenantContext->getTenantId();
        $prevStoreId = $tenantContext->getStoreId();

        try {
            $tenantContext->setTenantId((int) $order->tenant_id);
            $tenantContext->setStoreId($order->store_id !== null ? (int) $order->store_id : null);

            $this->transactionService->handleMidtransWebhook($payload);

            return response()->json([
                'status' => 'OK',
                'message' => 'Payment notification processed successfully.',
            ]);
        } finally {
            $tenantContext->clear();
            if ($prevTenantId !== null) {
                $tenantContext->setTenantId($prevTenantId);
                $tenantContext->setStoreId($prevStoreId);
            }
        }
    }
}
