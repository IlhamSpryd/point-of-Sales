<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Services\ShiftTemplateService;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class ShiftTemplateTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private TenantContext $context;

    private Store $store;

    private ShiftTemplateService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Shift Testing Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Senayan City',
        ]);

        $this->service = app(ShiftTemplateService::class);
    }

    public function test_standard_daytime_shift_duration_and_break_calculation(): void
    {
        $shift = $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-PAGI',
            'name' => 'Shift Pagi (Opening)',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'unpaid_break_minutes' => 60,
            'grace_period_minutes' => 15,
        ]);

        $this->assertFalse($shift->cross_midnight);
        $this->assertEquals(480, $shift->grossDurationMinutes()); // 8 jam = 480 menit
        $this->assertEquals(420, $shift->netWorkDurationMinutes()); // 480 - 60 = 420 menit (7 jam)
        $this->assertEquals(7.0, $shift->netWorkHours());
    }

    public function test_cross_midnight_shift_auto_detected_and_duration_calculated(): void
    {
        // 22:00 malam s/d 06:00 pagi berikutnya
        $shift = $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-MALAM',
            'name' => 'Shift Malam (Closing)',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'unpaid_break_minutes' => 60,
            'grace_period_minutes' => 15,
        ]);

        $this->assertTrue($shift->cross_midnight);
        $this->assertEquals(480, $shift->grossDurationMinutes()); // 8 jam = 480 menit
        $this->assertEquals(420, $shift->netWorkDurationMinutes()); // 7 jam net
        $this->assertEquals(7.0, $shift->netWorkHours());

        // Cek scheduled range pada tanggal 2026-10-15
        $range = $this->service->getScheduledTimeRange($shift, '2026-10-15');
        $this->assertEquals('2026-10-15 22:00:00', $range['start']->toDateTimeString());
        $this->assertEquals('2026-10-16 06:00:00', $range['end']->toDateTimeString());
    }

    public function test_unpaid_break_exceeding_or_equal_to_shift_duration_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-BAD',
            'name' => 'Shift Invalid Break',
            'start_time' => '08:00',
            'end_time' => '12:00', // 4 jam = 240 menit
            'unpaid_break_minutes' => 240, // 240 menit istirahat >= 240 menit kerja
        ]);
    }

    public function test_duplicate_shift_code_in_same_store_rejected(): void
    {
        $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-PAGI',
            'name' => 'Shift Pagi 1',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-PAGI',
            'name' => 'Shift Pagi Duplicate',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);
    }

    public function test_late_detection_and_grace_period_evaluation(): void
    {
        $shift = $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-SIANG',
            'name' => 'Shift Siang',
            'start_time' => '13:00',
            'end_time' => '21:00',
            'grace_period_minutes' => 15,
        ]);

        $scheduleDate = '2026-10-20';

        // 1. Tepat waktu: 12:55
        $this->assertFalse($this->service->isLate($shift, $scheduleDate, '2026-10-20 12:55:00'));
        $this->assertEquals(0, $this->service->calculateLateMinutes($shift, $scheduleDate, '2026-10-20 12:55:00'));

        // 2. Dalam batas toleransi (grace period): 13:10 (toleransi s/d 13:15)
        $this->assertFalse($this->service->isLate($shift, $scheduleDate, '2026-10-20 13:10:00'));
        $this->assertEquals(0, $this->service->calculateLateMinutes($shift, $scheduleDate, '2026-10-20 13:10:00'));

        // 3. Tepat di akhir toleransi: 13:15
        $this->assertFalse($this->service->isLate($shift, $scheduleDate, '2026-10-20 13:15:00'));
        $this->assertEquals(0, $this->service->calculateLateMinutes($shift, $scheduleDate, '2026-10-20 13:15:00'));

        // 4. Terlambat lewat batas toleransi: 13:25 (terlambat 25 menit dari 13:00)
        $this->assertTrue($this->service->isLate($shift, $scheduleDate, '2026-10-20 13:25:00'));
        $this->assertEquals(25, $this->service->calculateLateMinutes($shift, $scheduleDate, '2026-10-20 13:25:00'));
    }

    public function test_early_departure_detection(): void
    {
        $shift = $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-PAGI2',
            'name' => 'Shift Pagi 2',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $scheduleDate = '2026-10-22';

        // 1. Pulang tepat waktu atau lebih akhir: 16:05
        $this->assertFalse($this->service->isEarlyDeparture($shift, $scheduleDate, '2026-10-22 16:05:00'));
        $this->assertEquals(0, $this->service->calculateEarlyDepartureMinutes($shift, $scheduleDate, '2026-10-22 16:05:00'));

        // 2. Pulang lebih awal: 15:30 (pulang 30 menit lebih awal dari 16:00)
        $this->assertTrue($this->service->isEarlyDeparture($shift, $scheduleDate, '2026-10-22 15:30:00'));
        $this->assertEquals(30, $this->service->calculateEarlyDepartureMinutes($shift, $scheduleDate, '2026-10-22 15:30:00'));
    }

    public function test_cross_midnight_early_departure_detection(): void
    {
        $shift = $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-NIGHT2',
            'name' => 'Shift Malam 2',
            'start_time' => '23:00',
            'end_time' => '07:00',
            'cross_midnight' => true,
        ]);

        $scheduleDate = '2026-10-25';

        // Jadwal selesai adalah 2026-10-26 07:00:00
        // Pulang lebih awal jam 06:15 pagi tanggal 26 (45 menit lebih awal)
        $this->assertTrue($this->service->isEarlyDeparture($shift, $scheduleDate, '2026-10-26 06:15:00'));
        $this->assertEquals(45, $this->service->calculateEarlyDepartureMinutes($shift, $scheduleDate, '2026-10-26 06:15:00'));
    }

    public function test_multi_tenant_isolation_on_shift_templates(): void
    {
        $tenantOther = Tenant::create([
            'name' => 'Tenant Other Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($tenantOther->id);
        $storeOther = Store::create([
            'tenant_id' => $tenantOther->id,
            'name' => 'Outlet Surabaya',
        ]);
        $this->context->setTenantId($this->tenant->id);

        // Tidak boleh membuat shift untuk store tenant lain
        $this->expectException(ModelNotFoundException::class);
        $this->service->createShiftTemplate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $storeOther->id,
            'shift_code' => 'S-OTHER',
            'name' => 'Other Store Shift',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);
    }
}
