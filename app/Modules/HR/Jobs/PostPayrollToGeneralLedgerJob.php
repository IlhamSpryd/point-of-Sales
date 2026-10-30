<?php

declare(strict_types=1);

namespace App\Modules\HR\Jobs;

use App\Modules\HR\Models\PayrollBatch;
use App\Modules\HR\Services\PayrollPostingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PostPayrollToGeneralLedgerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $payrollBatchId,
        public string $postingType = 'ACCRUAL',
        public ?int $cashBankAccountId = null,
        public ?int $userId = null
    ) {}

    /**
     * Eksekusi penerusan jurnal penggajian ke Modul 7 General Ledger.
     */
    public function handle(PayrollPostingService $postingService): void
    {
        /** @var PayrollBatch|null $batch */
        $batch = PayrollBatch::find($this->payrollBatchId);

        if (! $batch) {
            return;
        }

        if ($this->postingType === 'PAYMENT' && $this->cashBankAccountId) {
            $postingService->postPaymentJournal($batch, $this->cashBankAccountId, $this->userId);
        } else {
            $postingService->postAccrualJournal($batch, $this->userId);
        }
    }
}
