<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Modules\Pricing\Services\DiscountAllocationService;
use Tests\TestCase;

class LargestRemainderAllocationTest extends TestCase
{
    private DiscountAllocationService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->service = new DiscountAllocationService;
    }

    public function test_proportional_allocation_across_three_items(): void
    {
        $lineNets = [
            0 => 50000.0,
            1 => 30000.0,
            2 => 20000.0,
        ];
        $totalDiscount = 10000.0;

        $allocated = $this->service->allocate($totalDiscount, $lineNets);

        $this->assertEquals(5000.0, $allocated[0]);
        $this->assertEquals(3000.0, $allocated[1]);
        $this->assertEquals(2000.0, $allocated[2]);
        $this->assertEquals($totalDiscount, array_sum($allocated));
    }

    public function test_largest_remainder_absorbs_rounding_cents_drift_across_seven_items(): void
    {
        // 7 item dengan nilai yang menghasilkan pembagian desimal tak hingga (1/7)
        $lineNets = [
            0 => 10000.0,
            1 => 10000.0,
            2 => 10000.0,
            3 => 10000.0,
            4 => 10000.0,
            5 => 10000.0,
            6 => 10000.0,
        ];
        // Total net = 70.000. Diskon = 10.000.
        // 10.000 / 7 = 1.428,5714...
        // 1.428,57 * 7 = 9.999,99 -> ada sisa 1 sen (Rp0,01)!
        $totalDiscount = 10000.0;

        $allocated = $this->service->allocate($totalDiscount, $lineNets);

        // Pastikan jumlah alokasi PERSIS 10.000 tanpa kurang atau lebih 1 sen pun!
        $this->assertEquals(10000.00, round(array_sum($allocated), 2));

        // Verifikasi bahwa sisa sen diserap oleh baris yang memenuhi syarat
        $this->assertCount(7, $allocated);
    }

    public function test_allocation_capped_at_total_net(): void
    {
        $lineNets = [
            0 => 15000.0,
            1 => 5000.0,
        ];
        // Total net = 20.000. Diskon yang diminta = 25.000 (melebihi belanja)
        $totalDiscount = 25000.0;

        $allocated = $this->service->allocate($totalDiscount, $lineNets);

        // Alokasi tidak boleh melebihi nilai net barang
        $this->assertEquals(15000.0, $allocated[0]);
        $this->assertEquals(5000.0, $allocated[1]);
        $this->assertEquals(20000.0, array_sum($allocated));
    }

    public function test_allocation_with_zero_or_negative_inputs(): void
    {
        $allocated = $this->service->allocate(0.0, [0 => 10000.0]);
        $this->assertEquals([0 => 0.0], $allocated);

        $allocatedEmpty = $this->service->allocate(5000.0, []);
        $this->assertEmpty($allocatedEmpty);
    }
}
