<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Adapters;

use App\Enums\ShippingDispatchStatus;
use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;

class PosIndonesiaCourierAdapter extends AbstractCourierAdapter
{
    public function getCourierCode(): string
    {
        return 'pos';
    }

    public function getCourierName(): string
    {
        return 'Pos Indonesia';
    }

    public function calculateRates(ShippingRateRequestDto $request): array
    {
        $weightKg = (string) $request->getWeightKg();
        $zoneMultiplier = $this->getZoneMultiplier($request->originPostalCode, $request->destinationPostalCode);
        $insuranceFee = $this->calculateInsurance($request->itemsValue);

        $services = [
            [
                'code' => 'POS_REG',
                'name' => 'Pos Reguler',
                'basePerKg' => '9000.00',
                'etd' => '2-4 hari',
                'description' => 'Jangkauan terluas hingga pelosok kecamatan dan desa',
            ],
            [
                'code' => 'POS_NEXTDAY',
                'name' => 'Pos Next Day',
                'basePerKg' => '18000.00',
                'etd' => '1 hari',
                'description' => 'Layanan kilat khusus antar kota besar',
            ],
            [
                'code' => 'POS_JUMBO',
                'name' => 'Pos Jumbo Ekonomi',
                'basePerKg' => '3800.00',
                'etd' => '4-8 hari',
                'description' => 'Layanan kargo paket besar (min 10kg)',
            ],
        ];

        $options = [];
        foreach ($services as $svc) {
            $effectiveWeight = $svc['code'] === 'POS_JUMBO' ? max('10', $weightKg) : $weightKg;
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
                features: ['bumn_guarantee', 'remote_area_coverage']
            );
        }

        return $options;
    }

    public function createWaybill(WaybillRequestDto $request): WaybillResponseDto
    {
        $awb = $this->generateMockWaybillNumber('POS');
        $bookingId = 'BK-POS-'.now()->format('Ymd').'-'.rand(1000, 9999);

        return new WaybillResponseDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $awb,
            bookingId: $bookingId,
            status: 'BOOKED',
            serviceCode: $request->serviceCode,
            shippingFee: '20000.00',
            labelUrl: "https://posindonesia.co.id/api/v1/shipping/{$awb}/label",
            estimatedDeliveryDate: now()->addDays(3)->format('Y-m-d')
        );
    }

    public function trackShipment(string $waybillNumber): ShipmentTrackingDto
    {
        return new ShipmentTrackingDto(
            courierCode: $this->getCourierCode(),
            waybillNumber: $waybillNumber,
            currentStatus: ShippingDispatchStatus::Delivered,
            statusDescription: 'Paket telah berhasil diserahkan kepada penerima di alamat tujuan.',
            senderName: 'Origin Sender Hub',
            recipientName: 'Bapak Rahmat (Penerima)',
            receivedBy: 'Rahmat (Yang Bersangkutan)',
            deliveredAt: now()->subMinutes(30)->toIso8601String(),
            history: [
                [
                    'timestamp' => now()->subDays(2)->toIso8601String(),
                    'status' => 'POSTING_LOKET',
                    'location' => 'Kantor Pos Jakarta Pasar Baru',
                    'note' => 'Paket diterima di loket Pos.',
                ],
                [
                    'timestamp' => now()->subDay()->toIso8601String(),
                    'status' => 'IN_TRANSIT_SPP',
                    'location' => 'Sentral Pengolahan Pos Surabaya',
                    'note' => 'Paket tiba di SPP tujuan.',
                ],
                [
                    'timestamp' => now()->subMinutes(30)->toIso8601String(),
                    'status' => 'DELIVERED',
                    'location' => 'Alamat Penerima',
                    'note' => 'Diterima oleh Rahmat (Yang Bersangkutan).',
                ],
            ]
        );
    }
}
