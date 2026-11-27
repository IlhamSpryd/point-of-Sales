<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Jobs;

use App\Core\Context\TenantContext;
use App\Modules\Workforce\Models\PayrollBatch;
use App\Modules\Workforce\Services\PayrollPostingService;
use App\Platform\Tenancy\TenantAwareJob;
use Illuminate\Support\Facades\DB;

class PostPayrollToGeneralLedgerJob extends TenantAwareJob
{
    public function __construct(
        public int $payrollBatchId,
        public string $postingType = 'ACCRUAL',
        public ?int $cashBankAccountId = null,
        public ?int $userId = null,
        ?int $tenantId = null
    ) {
        $resolvedTenantId = $tenantId
            ?? app(TenantContext::class)->getTenantId()
            ?? (int) DB::table('payroll_batches')->where('id', $payrollBatchId)->value('tenant_id');

        parent::__construct(tenantId: (int) $resolvedTenantId, actorId: $userId);
    }

    /**
     * Eksekusi penerusan jurnal penggajian ke Modul 7 General Ledger di dalam TenantAwareJob context.
     */
    protected function execute(): void
    {
        /** @var PayrollBatch|null $batch */
        $batch = PayrollBatch::where('tenant_id', $this->tenantId)->find($this->payrollBatchId);

        if (! $batch) {
            return;
        }

        $postingService = app(PayrollPostingService::class);

        if ($this->postingType === 'PAYMENT' && $this->cashBankAccountId) {
            $postingService->postPaymentJournal($batch, $this->cashBankAccountId, $this->userId);
        } else {
            $postingService->postAccrualJournal($batch, $this->userId);
        }
    }
}
