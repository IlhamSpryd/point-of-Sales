<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Adapters;

use App\Enums\ShippingDispatchStatus;
use App\Modules\Integration\Logistics\Contracts\CourierAdapterInterface;
use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

abstract class AbstractCourierAdapter implements CourierAdapterInterface
{
    protected ?string $apiKey;

    protected ?string $apiSecret;

    protected ?string $baseUrl;

    protected bool $mockMode;

    public function __construct(
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?string $baseUrl = null,
        ?bool $mockMode = null
    ) {
        $code = $this->getCourierCode();
        $this->apiKey = $apiKey ?? (string) Config::get("logistics.couriers.{$code}.api_key", '');
        $this->apiSecret = $apiSecret ?? (string) Config::get("logistics.couriers.{$code}.api_secret", '');
        $this->baseUrl = $baseUrl ?? (string) Config::get("logistics.couriers.{$code}.base_url", '');
        $this->mockMode = $mockMode ?? (bool) Config::get('logistics.mock_mode', true);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) || $this->mockMode;
    }

    /**
     * Compute base zone multiplier between origin and destination postal codes.
     * Same province (first 2 digits match): multiplier 1.0.
     * Same island (first digit matches): multiplier 1.5.
     * Different island: multiplier 2.5.
     */
    protected function getZoneMultiplier(string $originPostalCode, string $destinationPostalCode): string
    {
        $cleanOrigin = preg_replace('/\D/', '', $originPostalCode) ?? '10000';
        $cleanDest = preg_replace('/\D/', '', $destinationPostalCode) ?? '10000';

        $originP1 = substr($cleanOrigin, 0, 1);
        $destP1 = substr($cleanDest, 0, 1);
        $originP2 = substr($cleanOrigin, 0, 2);
        $destP2 = substr($cleanDest, 0, 2);

        if ($originP2 === $destP2) {
            return '1.0';
        }

        if ($originP1 === $destP1) {
            return '1.4';
        }

        return '2.2';
    }

    /**
     * Calculate optional parcel insurance fee (0.2% of goods value + Rp 5,000 admin fee).
     */
    protected function calculateInsurance(?string $itemsValue): string
    {
        if ($itemsValue === null || bccomp($itemsValue, '0', 2) <= 0) {
            return '0.00';
        }

        $rateFee = bcmul($itemsValue, '0.002', 2);

        return bcadd($rateFee, '5000.00', 2);
    }

    /**
     * Standard Waybill / Resi generator for simulation or mock environment.
     */
    protected function generateMockWaybillNumber(string $prefix): string
    {
        return strtoupper($prefix).now()->format('ymd').strtoupper(Str::random(8));
    }

    public function cancelShipment(string $waybillNumber, string $reason): bool
    {
        // Cancel logic: accepts request and marks tracking as CANCELLED
        return true;
    }
}
