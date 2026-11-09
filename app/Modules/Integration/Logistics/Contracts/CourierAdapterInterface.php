<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Contracts;

use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;

interface CourierAdapterInterface
{
    /**
     * Unique courier identification code (e.g. 'jne', 'sicepat', 'pos', 'jnt').
     */
    public function getCourierCode(): string;

    /**
     * Official brand name of the courier service.
     */
    public function getCourierName(): string;

    /**
     * Check whether courier integration is active and configured.
     */
    public function isConfigured(): bool;

    /**
     * Calculate available shipping rates for a given origin, destination, and package weight.
     *
     * @return list<ShippingRateOptionDto>
     */
    public function calculateRates(ShippingRateRequestDto $request): array;

    /**
     * Generate / book an official shipping waybill (AWB / Resi).
     */
    public function createWaybill(WaybillRequestDto $request): WaybillResponseDto;

    /**
     * Track package delivery status and detailed transit history.
     */
    public function trackShipment(string $waybillNumber): ShipmentTrackingDto;

    /**
     * Cancel a booked pickup or waybill before parcel hand-off.
     */
    public function cancelShipment(string $waybillNumber, string $reason): bool;
}
