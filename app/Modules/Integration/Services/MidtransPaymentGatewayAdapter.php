<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\PaymentGatewayInterface;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;

/**
 * Midtrans Payment Gateway Adapter (STR-12 / ADR-107)
 *
 * Encapsulates all third-party Midtrans SDK calls behind PaymentGatewayInterface
 * to prevent vendor lock-in and keep Sales domain free from vendor dependencies.
 */
class MidtransPaymentGatewayAdapter implements PaymentGatewayInterface
{
    /**
     * Request a Snap token from Midtrans Gateway.
     *
     * @param  array<string, mixed>  $params
     */
    public function createSnapToken(array $params, ?string $orderCode = null): string
    {
        $mockToken = config('services.midtrans.mock_snap_token');
        if (is_string($mockToken) && $mockToken !== '') {
            return $mockToken;
        }

        $serverKey = (string) config('services.midtrans.server_key', '');
        $isProduction = (bool) config('services.midtrans.is_production', false);

        if (
            ! $isProduction
            && (
                (app()->environment('testing') && ! config('services.midtrans.force_live_snap_in_tests', false))
                || $serverKey === ''
                || str_starts_with($serverKey, 'test')
                || str_starts_with($serverKey, 'mock')
            )
        ) {
            $code = $orderCode ?? ($params['transaction_details']['order_id'] ?? 'mock');

            return 'snap-sandbox-'.strtolower((string) $code);
        }

        $this->configureMidtrans($serverKey, $isProduction);

        return (string) Snap::getSnapToken($params);
    }

    public function getTransactionStatus(string $orderId): object
    {
        $mockStatus = config('services.midtrans.mock_status_response');
        if (is_array($mockStatus) || is_object($mockStatus)) {
            return (object) $mockStatus;
        }

        $serverKey = (string) config('services.midtrans.server_key', '');
        $isProduction = (bool) config('services.midtrans.is_production', false);

        $this->configureMidtrans($serverKey, $isProduction);

        return (object) Transaction::status($orderId);
    }

    private function configureMidtrans(string $serverKey, bool $isProduction): void
    {
        Config::$serverKey = $serverKey;
        Config::$isProduction = $isProduction;
        Config::$isSanitized = (bool) config('services.midtrans.is_sanitized', true);
        Config::$is3ds = (bool) config('services.midtrans.is_3ds', true);

        if (! $isProduction) {
            Config::$curlOptions = [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [],
            ];
        }
    }
}
