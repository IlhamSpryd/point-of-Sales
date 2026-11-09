<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Adapters;

use App\Enums\ShippingDispatchStatus;
use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;

class SiCepatCourierAdapter extends AbstractCourierAdapter
{
    public function getCourierCode(): string
    {
        return 'sicepat';
    }

    public function getCourierName(): string
    {
        return 'SiCepat Ekspres';
    }

    public function calculateRates(ShippingRateRequestDto $request): array
    {
        $weightKg = (string) $request->getWeightKg();
        $zoneMultiplier = $this->getZoneMultiplier($request->originPostalCode, $request->destinationPostalCode);
        $insuranceFee = $this->calculateInsurance($request->itemsValue);

        $services = [
            [
                'code' => 'SIUNT',
                'name' => 'SiCepat SiUntung',
                'basePerKg' => '9500.00',
                'etd' => '1-2 hari',
                'description' => 'Layanan cepat harga hemat standar SiCepat',
            ],
            [
                'code' => 'BEST',
                'name' => 'SiCepat BEST (Besok Sampai Tujuan)',
                'basePerKg' => '19000.00',
                'etd' => '1 hari',
                'description' => 'Layanan express prioritas esok hari sampai',
            ],
            [
                'code' => 'GOKIL',
                'name' => 'SiCepat Cargo Kilat (GOKIL)',
                'basePerKg' => '4000.00',
                'etd' => '3-5 hari',
                'description' => 'Layanan kargo minimum 10kg',
            ],
        ];

        $options = [];
        foreach ($services as $svc) {
            $effectiveWeight = $svc['code'] === 'GOKIL' ? max('10', $weightKg) : $weightKg;
            $baseCost = bcmul($svc['basePerKg'], (string) $effectiveWeight, 2);
            $totalCost = bcmul($baseCost, $zoneMultiplier, 2);

            $options[] = new ShippingRateOptionDto(
                courierCode: $this->getCourierCode(),
                courierName: $this->getCourierName(),
                serviceCode: $svc['code'],
                serviceName: $svc['name'],
                cost: $totalCost,
                estimatedDays: $svc['etd'],
                insuranceFee: $insuranceFee,
                description: $svc['description'],
                features: ['pickup', 'dropoff', 'realtime_gps']
            );
        }

        return $options;
    }

    public function createWaybill(WaybillRequestDto $request): WaybillResponseDto
    {
        $awb = $this->generateMockWaybillNumber('00');
        $bookingId = 'BK-SCP-'.now()->format('Ymd').'-'.rand(1000, 9999);

        return new WaybillResponseDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $awb,
            bookingId: $bookingId,
            status: 'BOOKED',
            serviceCode: $request->serviceCode,
            shippingFee: '22000.00',
            labelUrl: "https://api.sicepat.com/v1/waybill/{$awb}/print",
            estimatedDeliveryDate: now()->addDays(2)->format('Y-m-d')
        );
    }

    public function trackShipment(string $waybillNumber): ShipmentTrackingDto
    {
        return new ShipmentTrackingDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $waybillNumber,
            currentStatus: ShippingDispatchStatus::HandedToCourier,
            statusDescription: 'Paket telah di-pickup oleh SIGESIT SiCepat.',
            senderName: 'Origin Merchant Central',
            recipientName: 'Customer Penerima',
            history: [
                [
                    'timestamp' => now()->subHours(2)->toIso8601String(),
                    'status' => 'PICKED_UP',
                    'location' => 'Gerai SiCepat Jakarta Selatan',
                    'note' => 'Paket berhasil diambil kurir SIGESIT.',
                ],
            ]
        );
    }
}
