<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Exceptions\ClosedAccountingPeriodException;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Services\AccountingPeriodService;
use Tests\TestCase;

class AccountingPeriodServiceTest extends TestCase
{
    protected AccountingPeriodService $service;

    protected Tenant $tenant;

    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = app(AccountingPeriodService::class);
    }

    public function test_create_fiscal_year_periods_generates_12_months(): void
    {
        $periods = $this->service->createFiscalYearPeriods(2026, 1, $this->tenant->id);

        $this->assertCount(12, $periods);
        $this->assertEquals('2026-01', $periods->first()->period_code);
        $this->assertEquals('2026-12', $periods->last()->period_code);
        $this->assertEquals(AccountingPeriodStatusEnum::OPEN, $periods->first()->status);
    }

    public function test_resolve_open_period_for_date_succeeds_for_open_period(): void
    {
        $this->service->createFiscalYearPeriods(2026, 1, $this->tenant->id);

        $period = $this->service->resolveOpenPeriodForDate('2026-03-15', $this->tenant->id);

        $this->assertInstanceOf(AccountingPeriod::class, $period);
        $this->assertEquals('2026-03', $period->period_code);
        $this->assertTrue($period->isPostingAllowed());
    }

    public function test_resolve_open_period_throws_exception_if_period_is_closed(): void
    {
        $periods = $this->service->createFiscalYearPeriods(2026, 1, $this->tenant->id);
        $march = $periods->where('period_code', '2026-03')->first();

        $this->service->hardClosePeriod($march->id, $this->user);

        $this->expectException(ClosedAccountingPeriodException::class);
        $this->expectExceptionMessage('FIN-M7-08: Periode akuntansi 2026-03 berstatus CLOSED');

        $this->service->resolveOpenPeriodForDate('2026-03-20', $this->tenant->id);
    }

    public function test_period_lifecycle_transitions_and_reopen_logs(): void
    {
        $periods = $this->service->createFiscalYearPeriods(2026, 1, $this->tenant->id);
        $jan = $periods->first();

        // 1. Soft close
        $soft = $this->service->softClosePeriod($jan->id, $this->user);
        $this->assertEquals(AccountingPeriodStatusEnum::SOFT_CLOSED, $soft->status);
        $this->assertTrue($soft->isPostingAllowed());

        // 2. Hard close
        $hard = $this->service->hardClosePeriod($jan->id, $this->user);
        $this->assertEquals(AccountingPeriodStatusEnum::CLOSED, $hard->status);
        $this->assertFalse($hard->isPostingAllowed());
        $this->assertNotNull($hard->closed_at);

        // 3. Reopen
        $reopened = $this->service->reopenPeriod($jan->id, 'Koreksi penyesuaian akhir bulan', $this->user);
        $this->assertEquals(AccountingPeriodStatusEnum::OPEN, $reopened->status);
        $this->assertEquals('Koreksi penyesuaian akhir bulan', $reopened->reopen_reason);
        $this->assertNotNull($reopened->reopened_at);

        // Verifikasi log audit closing
        $this->assertDatabaseHas('period_closing_logs', [
            'tenant_id' => $this->tenant->id,
            'accounting_period_id' => $jan->id,
            'action' => 'REOPEN',
            'reason' => 'Koreksi penyesuaian akhir bulan',
        ]);
    }
}
