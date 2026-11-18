<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

/**
 * Payment Gateway Contract (STR-12 / ADR-107)
 *
 * Decouples domain payment processing from external vendor SDKs (e.g. Midtrans).
 */
interface PaymentGatewayInterface
{
    /**
     * Request a payment token (e.g. Snap token) from the payment gateway.
     *
     * @param  array<string, mixed>  $params
     */
    public function createSnapToken(array $params, ?string $orderCode = null): string;

    /**
     * Get real-time transaction status from the payment gateway.
     */
    public function getTransactionStatus(string $orderId): object;
}
