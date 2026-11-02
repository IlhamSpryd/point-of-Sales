<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentWebhookVerificationService
{
    /**
     * Hitung tanda tangan digital resmi Midtrans berbasis SHA-512.
     * Formula: SHA-512(order_id + status_code + gross_amount + server_key)
     */
    public function computeSignature(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $serverKey
    ): string {
        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }

    /**
     * Verifikasi keaslian webhook Midtrans.
     */
    public function verify(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $signatureKey,
        ?string $serverKey = null
    ): bool {
        $resolvedServerKey = $serverKey ?? (string) config('services.midtrans.server_key');
        if ($resolvedServerKey === '') {
            return false;
        }

        $expected = $this->computeSignature($orderId, $statusCode, $grossAmount, $resolvedServerKey);

        return hash_equals($expected, $signatureKey);
    }

    /**
     * Validasi tanda tangan Midtrans dan tolak jika palsu dengan HTTP 401 Unauthorized.
     *
     * @throws HttpException
     */
    public function assertValid(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $signatureKey,
        ?string $serverKey = null
    ): void {
        if (! $this->verify($orderId, $statusCode, $grossAmount, $signatureKey, $serverKey)) {
            throw new HttpException(401, 'Invalid payment webhook signature.');
        }
    }
}
