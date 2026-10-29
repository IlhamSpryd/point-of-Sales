<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportTaskStatus;
use App\Exports\CustomerStatementExport;
use App\Models\ExportTask;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessCustomerStatementExportJob implements ShouldBeUnique, ShouldQueue
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
            throw new \RuntimeException('Tenant ID is required to process customer statement export job.');
        }

        if ($this->exportTask->tenant_id !== null && (int) $this->tenantId !== (int) $this->exportTask->tenant_id) {
            throw new \RuntimeException('Tenant ID mismatch between job and task.');
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

    private function processExport(): void
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
            $customerId = (int) ($params['customer_id'] ?? 0);
            if ($customerId <= 0) {
                throw new \InvalidArgumentException('Customer ID is required for statement export.');
            }

            $start = Carbon::parse($params['start_date'] ?? $params['start'] ?? now()->startOfMonth());
            $end = Carbon::parse($params['end_date'] ?? $params['end'] ?? now());

            $dir = "exports/tenant_{$this->tenantId}";
            Storage::disk('local')->makeDirectory($dir);
            $filename = $dir.'/customer_statement_'.$customerId.'_'.$start->format('Ymd').'-'.$end->format('Ymd').'_'.$this->exportTask->id.'.xlsx';

            Excel::store(new CustomerStatementExport($customerId, $start, $end), $filename, 'local');
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
                'error_message' => 'Ekspor rekening koran gagal: '.$exception->getMessage(),
            ]);
        }
    }
}
