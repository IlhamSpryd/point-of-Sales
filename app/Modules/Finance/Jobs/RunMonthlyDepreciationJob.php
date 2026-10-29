<?php

declare(strict_types=1);

namespace App\Modules\Finance\Jobs;

use App\Models\AccountingPeriod;
use App\Models\User;
use App\Modules\Finance\Services\DepreciationService;
use App\Services\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunMonthlyDepreciationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $tenantId,
        public int $periodId,
        public ?int $actorUserId = null
    ) {}

    public function handle(
        DepreciationService $depreciationService,
        TenantContext $tenantContext
    ): void {
        $tenantContext->setTenantId($this->tenantId);

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->findOrFail($this->periodId);

        $actor = $this->actorUserId ? User::find($this->actorUserId) : null;
        if (! $actor) {
            $actor = User::where('tenant_id', $this->tenantId)->first();
        }

        if (! $actor) {
            Log::warning("RunMonthlyDepreciationJob: User actor tidak ditemukan untuk tenant #{$this->tenantId}.");

            return;
        }

        $result = $depreciationService->postMonthlyDepreciation($period, $this->tenantId, $actor);

        Log::info("RunMonthlyDepreciationJob selesai untuk tenant #{$this->tenantId}, periode #{$period->period_code}. Diproses: {$result['total_processed']} aset, Total penyusutan: {$result['total_depreciation_amount']}");
    }
}
