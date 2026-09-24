<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        if (! $orderId || ! $statusCode || ! $grossAmount || ! $signatureKey) {
            Log::warning('Midtrans notification: payload tidak lengkap.', $payload);

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

        try {
            // [SEC-006] gross_amount dari payload (yang keasliannya sudah
            // terverifikasi lewat pengecekan signature di atas) diteruskan
            // ke Service agar dicocokkan terhadap order_amount di database
            // sebelum status benar-benar diubah menjadi Paid.
            $this->transactionService->updateStatusFromMidtransNotification(
                orderCode: (string) $orderId,
                transactionStatus: (string) ($payload['transaction_status'] ?? ''),
                fraudStatus: $payload['fraud_status'] ?? null,
                grossAmount: (int) round((float) $grossAmount),
            );
        } catch (ModelNotFoundException) {
            Log::warning('Midtrans notification: order tidak ditemukan.', ['order_id' => $orderId]);

            return response()->json(['message' => 'order not found'], 404);
        }

        return response()->json(['message' => 'ok']);
    }
}
