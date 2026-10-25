<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuditTrailService;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\MenuCacheService;
use Mockery;
use PHPUnit\Framework\TestCase;

class InventoryCostCalculationTest extends TestCase
{
    private InventoryLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $menuCache = Mockery::mock(MenuCacheService::class);
        $auditTrail = Mockery::mock(AuditTrailService::class);

        $this->service = new InventoryLedgerService($menuCache, $auditTrail);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_initial_inbound_sets_unit_cost(): void
    {
        // Saldo awal 0, masuk 10 unit @ Rp 5.000
        $newCost = $this->service->calculateMovingAverageCost('0', '0.0000', '10', '5000.0000');

        $this->assertSame('5000.0000', $newCost);
    }

    public function test_weighted_average_cost_calculation(): void
    {
        // Saldo lama: 10 unit @ Rp 5.000 (total Rp 50.000)
        // Masuk: 10 unit @ Rp 6.000 (total Rp 60.000)
        // Total: 20 unit, nilai Rp 110.000 -> rata-rata Rp 5.500
        $newCost = $this->service->calculateMovingAverageCost('10', '5000.0000', '10', '6000.0000');

        $this->assertSame('5500.0000', $newCost);
    }

    public function test_weighted_average_cost_with_subsequent_inbound(): void
    {
        // Saldo lama: 20 unit @ Rp 5.500 (total Rp 110.000)
        // Masuk: 5 unit @ Rp 4.000 (total Rp 20.000)
        // Total: 25 unit, nilai Rp 130.000 -> rata-rata Rp 5.200
        $newCost = $this->service->calculateMovingAverageCost('20', '5500.0000', '5', '4000.0000');

        $this->assertSame('5200.0000', $newCost);
    }

    public function test_fractional_ingredient_weighted_average_cost(): void
    {
        // Bahan baku: 2.5000 kg @ Rp 12.000,0000 (Rp 30.000)
        // Masuk: 1.5000 kg @ Rp 16.000,0000 (Rp 24.000)
        // Total: 4.0000 kg, nilai Rp 54.000 -> rata-rata Rp 13.500,0000
        $newCost = $this->service->calculateMovingAverageCost('2.5000', '12000.0000', '1.5000', '16000.0000');

        $this->assertSame('13500.0000', $newCost);
    }

    public function test_zero_incoming_quantity_preserves_old_cost(): void
    {
        $newCost = $this->service->calculateMovingAverageCost('15', '7500.0000', '0', '10000.0000');

        $this->assertSame('7500.0000', $newCost);
    }
}
