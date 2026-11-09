<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Adapters;

use App\Enums\ShippingDispatchStatus;
use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;

class JneCourierAdapter extends AbstractCourierAdapter
{
    public function getCourierCode(): string
    {
        return 'jne';
    }

    public function getCourierName(): string
    {
        return 'JNE Express';
    }

    public function calculateRates(ShippingRateRequestDto $request): array
    {
        $weightKg = (string) $request->getWeightKg();
        $zoneMultiplier = $this->getZoneMultiplier($request->originPostalCode, $request->destinationPostalCode);
        $insuranceFee = $this->calculateInsurance($request->itemsValue);

        $services = [
            [
                'code' => 'REG',
                'name' => 'JNE Reguler',
                'basePerKg' => '10000.00',
                'etd' => '2-3 hari',
                'description' => 'Layanan reguler ke seluruh kota di Indonesia',
            ],
            [
                'code' => 'YES',
                'name' => 'JNE Yakin Esok Sampai (YES)',
                'basePerKg' => '22000.00',
                'etd' => '1 hari',
                'description' => 'Layanan premium tiba di tujuan keesokan harinya',
            ],
            [
                'code' => 'OKE',
                'name' => 'JNE Ongkos Kirim Ekonomis (OKE)',
                'basePerKg' => '8000.00',
                'etd' => '3-5 hari',
                'description' => 'Layanan ekonomis dengan estimasi waktu pengiriman lebih santai',
            ],
            [
                'code' => 'JTR',
                'name' => 'JNE Trucking (JTR)',
                'basePerKg' => '4500.00',
                'etd' => '4-7 hari',
                'description' => 'Layanan kargo berat (minimum berat perhitungan 10kg)',
            ],
        ];

        $options = [];
        foreach ($services as $svc) {
            $effectiveWeight = $svc['code'] === 'JTR' ? max('10', $weightKg) : $weightKg;
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
                features: ['tracking', 'dropoff', 'cod_available']
            );
        }

        return $options;
    }

    public function createWaybill(WaybillRequestDto $request): WaybillResponseDto
    {
        $awb = $this->generateMockWaybillNumber('JNE');
        $bookingId = 'BK-JNE-'.now()->format('Ymd').'-'.rand(1000, 9999);

        return new WaybillResponseDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $awb,
            bookingId: $bookingId,
            status: 'BOOKED',
            serviceCode: $request->serviceCode,
            shippingFee: '25000.00',
            labelUrl: "https://api.jne.co.id/v1/labels/{$awb}.pdf",
            estimatedDeliveryDate: now()->addDays(2)->format('Y-m-d')
        );
    }

    public function trackShipment(string $waybillNumber): ShipmentTrackingDto
    {
        return new ShipmentTrackingDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $waybillNumber,
            currentStatus: ShippingDispatchStatus::InTransit,
            statusDescription: 'Paket sedang dalam perjalanan menuju kota tujuan (Hub Gateway Transit).',
            senderName: 'Origin Store Warehouse',
            recipientName: 'Mitra Pembeli Retail',
            history: [
                [
                    'timestamp' => now()->subHours(12)->toIso8601String(),
                    'status' => 'SHIPMENT_RECEIVED',
                    'location' => 'JNE Agen Origin Jakarta Pusat',
                    'note' => 'Paket diterima oleh agen JNE.',
                ],
                [
                    'timestamp' => now()->subHours(6)->toIso8601String(),
                    'status' => 'DEPARTED_GATEWAY',
                    'location' => 'JNE Main Sorting Center Tomang Jakarta',
                    'note' => 'Paket diberangkatkan menuju sorting center tujuan.',
                ],
                [
                    'timestamp' => now()->subHours(1)->toIso8601String(),
                    'status' => 'IN_TRANSIT',
                    'location' => 'JNE Hub Transit Jawa Barat',
                    'note' => 'Paket transit di sorting hub.',
                ],
            ]
        );
    }
}
