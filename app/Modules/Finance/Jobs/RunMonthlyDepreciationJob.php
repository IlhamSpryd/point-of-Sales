<?php

declare(strict_types=1);

namespace App\Modules\Finance\Jobs;

use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Services\DepreciationService;
use App\Platform\Tenancy\TenantAwareJob;
use Illuminate\Support\Facades\Log;

class RunMonthlyDepreciationJob extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(
        int $tenantId,
        public int $periodId,
        public ?int $actorUserId = null
    ) {
        parent::__construct(tenantId: $tenantId, actorId: $actorUserId);
    }

    protected function execute(): void
    {
        $period = AccountingPeriod::query()
            ->where('tenant_id', $this->tenantId)
            ->findOrFail($this->periodId);

        $actor = $this->actorUserId
            ? \App\Modules\Organization\Models\User::where('tenant_id', $this->tenantId)->find($this->actorUserId)
            : null;
        if (! $actor) {
            $actor = \App\Modules\Organization\Models\User::where('tenant_id', $this->tenantId)->first();
        }

        if (! $actor) {
            Log::warning("RunMonthlyDepreciationJob: User actor tidak ditemukan untuk tenant #{$this->tenantId}.");

            return;
        }

        $result = app(DepreciationService::class)->postMonthlyDepreciation($period, $this->tenantId, $actor);

        Log::info("RunMonthlyDepreciationJob selesai untuk tenant #{$this->tenantId}, periode #{$period->period_code}. Diproses: {$result['total_processed']} aset, Total penyusutan: {$result['total_depreciation_amount']}");
    }
}
