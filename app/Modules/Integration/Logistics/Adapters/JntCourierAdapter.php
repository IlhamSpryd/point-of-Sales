<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Adapters;

use App\Enums\ShippingDispatchStatus;
use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;

class JntCourierAdapter extends AbstractCourierAdapter
{
    public function getCourierCode(): string
    {
        return 'jnt';
    }

    public function getCourierName(): string
    {
        return 'J&T Express';
    }

    public function calculateRates(ShippingRateRequestDto $request): array
    {
        $weightKg = (string) $request->getWeightKg();
        $zoneMultiplier = $this->getZoneMultiplier($request->originPostalCode, $request->destinationPostalCode);
        $insuranceFee = $this->calculateInsurance($request->itemsValue);

        $services = [
            [
                'code' => 'EZ',
                'name' => 'J&T EZ (Reguler)',
                'basePerKg' => '10000.00',
                'etd' => '2-3 hari',
                'description' => 'Layanan reguler 365 hari tanpa libur',
            ],
            [
                'code' => 'JNT_SUPER',
                'name' => 'J&T Super (Next Day)',
                'basePerKg' => '20000.00',
                'etd' => '1 hari',
                'description' => 'Layanan premium prioritas kecepatan tinggi',
            ],
            [
                'code' => 'JNT_ECO',
                'name' => 'J&T Economy',
                'basePerKg' => '7500.00',
                'etd' => '3-6 hari',
                'description' => 'Tarif bersahabat untuk paket santai',
            ],
        ];

        $options = [];
        foreach ($services as $svc) {
            $baseCost = bcmul($svc['basePerKg'], $weightKg, 2);
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
                features: ['operating_365_days', 'cod_nationwide']
            );
        }

        return $options;
    }

    public function createWaybill(WaybillRequestDto $request): WaybillResponseDto
    {
        $awb = $this->generateMockWaybillNumber('JT');
        $bookingId = 'BK-JNT-'.now()->format('Ymd').'-'.rand(1000, 9999);

        return new WaybillResponseDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $awb,
            bookingId: $bookingId,
            status: 'BOOKED',
            serviceCode: $request->serviceCode,
            shippingFee: '21000.00',
            labelUrl: "https://jet.co.id/api/waybill/{$awb}/print",
            estimatedDeliveryDate: now()->addDays(2)->format('Y-m-d')
        );
    }

    public function trackShipment(string $waybillNumber): ShipmentTrackingDto
    {
        return new ShipmentTrackingDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $waybillNumber,
            currentStatus: ShippingDispatchStatus::InTransit,
            statusDescription: 'Paket sedang diantar oleh Sprinter J&T menuju alamat tujuan.',
            senderName: 'Origin Merchant Central',
            recipientName: 'Customer Penerima',
            history: [
                [
                    'timestamp' => now()->subHours(8)->toIso8601String(),
                    'status' => 'PICKED_UP',
                    'location' => 'Drop Point J&T Menteng',
                    'note' => 'Paket telah diambil Sprinter.',
                ],
                [
                    'timestamp' => now()->subHours(2)->toIso8601String(),
                    'status' => 'ON_DELIVERY',
                    'location' => 'Drop Point J&T Surabaya Rungkut',
                    'note' => 'Paket sedang dibawa Sprinter menuju alamat pengantaran.',
                ],
            ]
        );
    }
}
