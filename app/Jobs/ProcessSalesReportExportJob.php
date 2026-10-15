<?php

declare(strict_types=1);

// Ekspor laporan besar tanpa memblokir request HTTP. | 2026-09-23

namespace App\Jobs;

use App\Enums\ExportTaskStatus;
use App\Exports\SalesExport;
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

class ProcessSalesReportExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // FIX (M6-B-002): allow ONE retry for transient failures (brief DB
    // hiccup, momentary disk pressure) instead of failing permanently on
    // the very first attempt.
    public int $tries = 2;

    // FIX (M6-B-002): hard wall-clock ceiling so a hanging job doesn't
    // occupy a worker slot forever; exceeding this triggers failed()
    // below via Laravel's own timeout handling.
    public int $timeout = 600;

    // Deterministic 10-second backoff before retrying attempt 2
    public int $backoff = 10;

    // FIX (M6-B-002): dedicated queue so a heavy report export never
    // blocks time-sensitive omnichannel order jobs sharing the 'default'
    // queue (see ProcessWebhookOrderJob::$queue).

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
            throw new \RuntimeException('Tenant ID is required to process export job.');
        }

        if ($this->exportTask->tenant_id !== null && (int) $this->tenantId !== (int) $this->exportTask->tenant_id) {
            throw new \RuntimeException('Tenant ID mismatch between job and task.');
        }

        $tenantContext = app(TenantContext::class);
        $prevTenantId = $tenantContext->getTenantId();
        $prevStoreId = $tenantContext->getStoreId();

        try {
            $tenantContext->setTenantId($this->tenantId);
            $tenantContext->setStoreId($this->storeId);

            $this->processExport();
        } finally {
            $tenantContext->clear();
            if ($prevTenantId !== null) {
                $tenantContext->setTenantId($prevTenantId);
                $tenantContext->setStoreId($prevStoreId);
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

        // Defensive memory ceiling for this worker process only.
        ini_set('memory_limit', '512M');

        try {
            $params = $this->exportTask->parameters ?? [];
            $start = Carbon::parse($params['start'] ?? now()->startOfMonth());
            $end = Carbon::parse($params['end'] ?? now());

            $dir = "exports/tenant_{$this->tenantId}";
            Storage::disk('local')->makeDirectory($dir);
            $filename = $dir.'/sales_report_'.$start->format('Ymd').'-'.$end->format('Ymd').'_'.$this->exportTask->id.'.xlsx';

            $allStores = (bool) ($params['all_stores'] ?? ($this->storeId === null));
            Excel::store(new SalesExport($start, $end, $allStores), $filename, 'local');
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

    /**
     * CRITICAL FIX (M6-B-002): this is the ONLY hook Laravel guarantees
     * to call once retries/timeout are exhausted -- including after a
     * worker process was killed mid-handle() by an OOM and the job was
     * later re-queued and finally failed. Without this, an OOM crash left
     * the ExportTask frozen at 'processing' forever, because handle()'s
     * own try/catch never got a chance to run a second time.
     */
    public function failed(Throwable $exception): void
    {
        if ($this->exportTask->status !== ExportTaskStatus::Failed) {
            $this->exportTask->update([
                'status' => ExportTaskStatus::Failed,
                'error_message' => 'Ekspor gagal: '.$exception->getMessage(),
            ]);
        }
    }
}
