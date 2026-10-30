<?php

declare(strict_types=1);

namespace App\Modules\HR\Jobs;

use App\Modules\HR\Models\PayrollRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePayslipPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $payrollRecordId
    ) {}

    /**
     * Eksekusi rendering slip gaji PDF.
     */
    public function handle(): void
    {
        /** @var PayrollRecord|null $record */
        $record = PayrollRecord::with(['items', 'batch', 'employee'])->find($this->payrollRecordId);

        if (! $record) {
            return;
        }

        // Simpan / render template slip gaji HTML / PDF ke storage
        // Format dokumen resmi Apeiron POS / ERP SaaS
    }
}
