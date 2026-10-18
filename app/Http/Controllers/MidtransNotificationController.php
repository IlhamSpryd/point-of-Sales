<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MidtransNotificationController menerima webhook server-to-server dari
 * Midtrans setiap kali status pembayaran berubah (capture/settlement/deny/
 * cancel/expire). Ini SATU-SATUNYA sumber kebenaran OTOMATIS untuk transisi
 * order_status pembayaran non-tunai -- dipakai bersama oleh Self-Order
 * pelanggan maupun Kasir POS, karena keduanya memanggil
 * TransactionService::createTransaction() yang sama.
 *
 * Endpoint ini publik (tanpa auth, tanpa CSRF -- lihat bootstrap/app.php)
 * karena dipanggil langsung oleh server Midtrans, bukan browser. Keamanan
 * dijamin oleh verifikasi signature_key (SHA512), bukan sesi/cookie.
 */
class MidtransNotificationController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $orderId = $payload['order_id'] ?? null;
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;
        $signatureKey = $payload['signature_key'] ?? null;

        // SEC-07: Log metadata terkurasi saja; jangan bocorkan signature_key, nomor VA, atau data sensitif customer ke logs.
        Log::info('Midtrans webhook received:', [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'transaction_status' => $payload['transaction_status'] ?? null,
            'payment_type' => $payload['payment_type'] ?? null,
        ]);

        if (! $orderId || ! $statusCode || ! $grossAmount || ! $signatureKey) {
            Log::warning('Midtrans notification: payload tidak lengkap.', [
                'order_id' => $orderId,
                'has_status_code' => ! empty($statusCode),
                'has_gross_amount' => ! empty($grossAmount),
                'has_signature_key' => ! empty($signatureKey),
            ]);

            return response()->json(['message' => 'invalid payload'], 400);
        }

        // PATCH FOR S-06: Fail-closed jika server key kosong.
        $serverKey = (string) config('services.midtrans.server_key');
        if ($serverKey === '') {
            Log::critical('Midtrans notification DITOLAK: MIDTRANS_SERVER_KEY kosong.');

            return response()->json(['message' => 'server misconfigured'], 500);
        }

        // Zero-Trust: JANGAN PERNAH percaya transaction_status dari payload
        // sebelum signature terverifikasi -- siapa pun bisa POST payload
        // palsu ke endpoint publik ini mengklaim status "settlement".
        $expectedSignature = hash(
            'sha512',
            $orderId.$statusCode.$grossAmount.$serverKey
        );

        if (! hash_equals($expectedSignature, (string) $signatureKey)) {
            Log::warning('Midtrans notification: signature TIDAK VALID.', ['order_id' => $orderId]);

            return response()->json(['message' => 'invalid signature'], 403);
        }

        $tenantContext = app(TenantContext::class);
        $prevTenantId = $tenantContext->getTenantId();
        $prevStoreId = $tenantContext->getStoreId();

        try {
            // Kompatibilitas ganda: cek apakah order_id adalah provider_order_id di payment_attempts atau order_code di orders
            $attempt = PaymentAttempt::withoutGlobalScopes()->where('provider_order_id', (string) $orderId)->first();
            $order = null;

            if ($attempt) {
                $order = Order::withoutGlobalScopes()->where('id', $attempt->order_id)->first();
            } else {
                $order = Order::withoutGlobalScopes()->where('order_code', (string) $orderId)->first();
            }

            if (! $order) {
                Log::warning('Midtrans notification: order tidak ditemukan.', ['order_id' => $orderId]);

                return response()->json(['message' => 'order not found'], 404);
            }

            if (! $order->tenant_id) {
                Log::warning('Midtrans notification: order tidak memiliki tenant_id.', ['order_id' => $orderId]);

                return response()->json(['message' => 'invalid order tenancy'], 422);
            }

            $tenantContext->setTenantId((int) $order->tenant_id);
            $tenantContext->setStoreId($order->store_id !== null ? (int) $order->store_id : null);

            $this->transactionService->handleMidtransWebhook($payload);
        } catch (ModelNotFoundException) {
            Log::warning('Midtrans notification: order tidak ditemukan.', ['order_id' => $orderId]);

            return response()->json(['message' => 'order not found'], 404);
        } finally {
            $tenantContext->clear();
            if ($prevTenantId !== null) {
                $tenantContext->setTenantId($prevTenantId);
                $tenantContext->setStoreId($prevStoreId);
            }
        }

        return response()->json(['message' => 'ok']);
    }
}
