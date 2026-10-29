<?php

declare(strict_types=1);

namespace App\Modules\Finance\Jobs;

use App\Enums\ExportTaskStatus;
use App\Exports\FinancialStatementExport;
use App\Models\ExportTask;
use App\Modules\Finance\Services\FinancialStatementService;
use App\Services\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateFinancialStatementExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $backoff = 10;

    public int $tenantId;

    public ?int $storeId = null;

    public function __construct(
        public ExportTask $exportTask,
        ?int $tenantId = null,
        ?int $storeId = null
    ) {
        $this->tenantId = $tenantId ?? (int) $exportTask->tenant_id;
        $this->storeId = $storeId ?? $exportTask->store_id;
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->exportTask->requested_by.'_'.md5(json_encode($this->exportTask->parameters));
    }

    public function handle(): void
    {
        if (empty($this->tenantId)) {
            throw new \RuntimeException('Tenant ID wajib disertakan untuk memproses ekspor laporan keuangan.');
        }

        if ($this->exportTask->tenant_id !== null && (int) $this->tenantId !== (int) $this->exportTask->tenant_id) {
            throw new \RuntimeException('Tenant ID tidak cocok antara job dan export task.');
        }

        $tenantContext = app(TenantContext::class);
        $prevTenantId = $tenantContext->getTenantId();
        $prevStoreId = $tenantContext->getStoreId();

        try {
            $tenantContext->setTenantId($this->tenantId);
            if ($this->storeId !== null) {
                $tenantContext->setStoreId($this->storeId);
            }

            $this->processExport();
        } finally {
            $tenantContext->clear();
            if ($prevTenantId !== null) {
                $tenantContext->setTenantId($prevTenantId);
                if ($prevStoreId !== null) {
                    $tenantContext->setStoreId($prevStoreId);
                }
            }
        }
    }

    protected function processExport(): void
    {
        if ($this->exportTask->status !== ExportTaskStatus::Pending && $this->exportTask->status !== ExportTaskStatus::Processing) {
            return;
        }

        if ($this->exportTask->status !== ExportTaskStatus::Processing) {
            $this->exportTask->update(['status' => ExportTaskStatus::Processing]);
        }

        ini_set('memory_limit', '512M');

        try {
            $params = $this->exportTask->parameters ?? [];
            $reportType = (string) ($params['report_type'] ?? 'trial_balance');
            $periodId = (int) ($params['period_id'] ?? 0);
            $storeId = isset($params['store_id']) ? (int) $params['store_id'] : $this->storeId;
            $yearToDate = (bool) ($params['year_to_date'] ?? false);

            if ($periodId <= 0) {
                throw new \InvalidArgumentException('Accounting Period ID wajib disertakan.');
            }

            $statementService = app(FinancialStatementService::class);

            $data = match ($reportType) {
                'trial_balance' => $statementService->generateTrialBalance($periodId, $storeId, $this->tenantId),
                'profit_and_loss' => $statementService->generateProfitAndLoss($periodId, $storeId, $this->tenantId, $yearToDate),
                'balance_sheet' => $statementService->generateBalanceSheet($periodId, $storeId, $this->tenantId),
                'cash_flow' => $statementService->generateCashFlowStatement($periodId, $storeId, $this->tenantId),
                default => throw new \InvalidArgumentException("Tipe laporan {$reportType} tidak didukung."),
            };

            $dir = "exports/tenant_{$this->tenantId}";
            Storage::disk('local')->makeDirectory($dir);
            $filename = "{$dir}/financial_statement_{$reportType}_period_{$periodId}_{$this->exportTask->id}.xlsx";

            Excel::store(new FinancialStatementExport($reportType, $data), $filename, 'local');
            $fullPath = Storage::disk('local')->path($filename);

            $this->exportTask->update([
                'status' => ExportTaskStatus::Completed,
                'file_path' => $filename,
                'file_size_bytes' => file_exists($fullPath) ? filesize($fullPath) : null,
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $this->fail($e);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->exportTask->status !== ExportTaskStatus::Failed) {
            $this->exportTask->update([
                'status' => ExportTaskStatus::Failed,
                'error_message' => 'Ekspor laporan keuangan gagal: '.$exception->getMessage(),
            ]);
        }
    }
}
