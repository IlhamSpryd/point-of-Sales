<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\ShippingDispatchStatus;
use App\Modules\Integration\Logistics\Adapters\JneCourierAdapter;
use App\Modules\Integration\Logistics\Adapters\JntCourierAdapter;
use App\Modules\Integration\Logistics\Adapters\PosIndonesiaCourierAdapter;
use App\Modules\Integration\Logistics\Adapters\SiCepatCourierAdapter;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\Services\Logistics3PlAggregatorService;
use InvalidArgumentException;
use Tests\TestCase;

class Logistics3PlServiceTest extends TestCase
{
    private Logistics3PlAggregatorService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->service = new Logistics3PlAggregatorService(
            new JneCourierAdapter(mockMode: true),
            new SiCepatCourierAdapter(mockMode: true),
            new PosIndonesiaCourierAdapter(mockMode: true),
            new JntCourierAdapter(mockMode: true)
        );
    }

    public function test_all_four_canonical_couriers_are_registered_and_available(): void
    {
        $couriers = $this->service->getAvailableCouriers();

        $this->assertArrayHasKey('jne', $couriers);
        $this->assertArrayHasKey('sicepat', $couriers);
        $this->assertArrayHasKey('pos', $couriers);
        $this->assertArrayHasKey('jnt', $couriers);

        $this->assertSame('JNE Express', $couriers['jne']);
        $this->assertSame('SiCepat Ekspres', $couriers['sicepat']);
        $this->assertSame('Pos Indonesia', $couriers['pos']);
        $this->assertSame('J&T Express', $couriers['jnt']);
    }

    public function test_rate_calculation_returns_options_across_all_couriers_with_weight_and_distance(): void
    {
        $request = new ShippingRateRequestDto(
            originPostalCode: '10110',
            originCity: 'Jakarta Pusat',
            destinationPostalCode: '60271',
            destinationCity: 'Surabaya',
            weightGrams: 2000,
            itemsValue: '1500000.00'
        );

        $rates = $this->service->calculateAllRates($request);

        $this->assertArrayHasKey('jne', $rates);
        $this->assertArrayHasKey('sicepat', $rates);
        $this->assertArrayHasKey('pos', $rates);
        $this->assertArrayHasKey('jnt', $rates);

        // Verify JNE has multiple service options (REG, YES, OKE, JTR)
        $jneCodes = array_map(fn ($r) => $r->serviceCode, $rates['jne']);
        $this->assertContains('REG', $jneCodes);
        $this->assertContains('YES', $jneCodes);

        // Verify insurance fee calculation (0.2% of 1,500,000 = 3,000 + 5,000 admin = 8,000)
        $regOption = collect($rates['jne'])->firstWhere('serviceCode', 'REG');
        $this->assertNotNull($regOption);
        $this->assertSame('8000.00', $regOption->insuranceFee);
        $this->assertGreaterThan(0, (float) $regOption->totalCost);
    }

    public function test_volumetric_weight_calculation_rules(): void
    {
        // 50cm x 40cm x 30cm = 60,000 cm3 -> 60,000 / 6000 * 1000 = 10,000g (10kg)
        // Actual physical weight is only 1,500g, but chargeable weight should be 10,000g
        $request = new ShippingRateRequestDto(
            originPostalCode: '10110',
            originCity: 'Jakarta Pusat',
            destinationPostalCode: '10220',
            destinationCity: 'Jakarta Pusat',
            weightGrams: 1500,
            dimensionsCm: [
                'length' => 50,
                'width' => 40,
                'height' => 30,
            ]
        );

        $this->assertSame(10000, $request->getChargeableWeightGrams());
        $this->assertEquals(10.0, $request->getWeightKg());
    }

    public function test_cheapest_and_fastest_rate_selection(): void
    {
        $request = new ShippingRateRequestDto(
            originPostalCode: '10110',
            originCity: 'Jakarta Pusat',
            destinationPostalCode: '40115',
            destinationCity: 'Bandung',
            weightGrams: 1000
        );

        $cheapest = $this->service->getCheapestRate($request);
        $this->assertNotNull($cheapest);

        $fastest = $this->service->getFastestRate($request);
        $this->assertNotNull($fastest);

        // Fastest service should have 1 day delivery estimate
        $this->assertStringContainsString('1', $fastest->estimatedDays);
        $this->assertGreaterThan(0, (float) $cheapest->totalCost);
    }

    public function test_fastest_rate_prioritizes_same_day_delivery_over_multi_day(): void
    {
        $mockAdapter = new class extends \App\Modules\Integration\Logistics\Adapters\AbstractCourierAdapter {
            public function getCourierCode(): string { return 'fastmock'; }
            public function getCourierName(): string { return 'FastMock Express'; }
            public function calculateRates(\App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto $request): array {
                return [
                    new \App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto(
                        courierCode: 'fastmock',
                        courierName: 'FastMock Express',
                        serviceCode: 'EXP',
                        serviceName: 'Express 1 Day',
                        cost: '30000.00',
                        estimatedDays: '1 hari'
                    ),
                    new \App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto(
                        courierCode: 'fastmock',
                        courierName: 'FastMock Express',
                        serviceCode: 'SAMEDAY',
                        serviceName: 'Instant Same Day',
                        cost: '50000.00',
                        estimatedDays: 'Same Day'
                    ),
                ];
            }
            public function createWaybill(\App\Modules\Integration\Logistics\DTOs\WaybillRequestDto $request): \App\Modules\Integration\Logistics\DTOs\WaybillResponseDto {
                return new \App\Modules\Integration\Logistics\DTOs\WaybillResponseDto('fastmock', 'AWB1', 'BK1', 'BOOKED', 'SAMEDAY', '50000.00', null, null);
            }
            public function trackShipment(string $waybillNumber): \App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto {
                return new \App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto('fastmock', 'FastMock', $waybillNumber, \App\Enums\ShippingDispatchStatus::InTransit, 'On way');
            }
        };

        $aggregator = new Logistics3PlAggregatorService();
        $aggregator->registerAdapter($mockAdapter);

        $request = new ShippingRateRequestDto(
            originPostalCode: '10110',
            originCity: 'Jakarta Pusat',
            destinationPostalCode: '10110',
            destinationCity: 'Jakarta Pusat',
            weightGrams: 500
        );

        $fastest = $aggregator->getFastestRate($request);
        $this->assertNotNull($fastest);
        $this->assertSame('SAMEDAY', $fastest->serviceCode);
        $this->assertSame('Same Day', $fastest->estimatedDays);
    }

    public function test_waybill_creation_and_tracking_workflow(): void
    {
        $waybillRequest = new WaybillRequestDto(
            tenantId: 1,
            orderId: 99,
            courierCode: 'jne',
            serviceCode: 'REG',
            sender: [
                'name' => 'Apeiron Central Hub',
                'phone' => '081211112222',
                'address' => 'Jl. Gatot Subroto No. 45',
                'postal_code' => '12930',
                'city' => 'Jakarta Selatan',
            ],
            recipient: [
                'name' => 'Budi Santoso',
                'phone' => '081299998888',
                'address' => 'Jl. Pemuda No. 12',
                'postal_code' => '50132',
                'city' => 'Semarang',
            ],
            weightGrams: 1500,
            goodsDescription: 'Spare Parts Komputer',
            itemValue: '500000.00'
        );

        $response = $this->service->createWaybill($waybillRequest);

        $this->assertSame('jne', $response->courierCode);
        $this->assertSame('BOOKED', $response->status);
        $this->assertStringStartsWith('JNE', $response->waybillNumber);
        $this->assertNotEmpty($response->bookingId);
        $this->assertNotNull($response->labelUrl);

        // Track the created shipment
        $tracking = $this->service->trackShipment('jne', $response->waybillNumber);

        $this->assertSame('jne', $tracking->courierCode);
        $this->assertSame($response->waybillNumber, $tracking->waybillNumber);
        $this->assertSame(ShippingDispatchStatus::InTransit, $tracking->currentStatus);
        $this->assertNotEmpty($tracking->history);
        $this->assertSame('In Transit', $tracking->currentStatus->label());

        // Cancel the shipment
        $cancelled = $this->service->cancelShipment('jne', $response->waybillNumber, 'Customer changed delivery address.');
        $this->assertTrue($cancelled);
    }

    public function test_invalid_courier_code_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->getAdapter('non_existent_courier');
    }
}
