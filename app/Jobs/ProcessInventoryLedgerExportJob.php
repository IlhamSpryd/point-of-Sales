<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportTaskStatus;
use App\Exports\InventoryLedgerExport;
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

class ProcessInventoryLedgerExportJob implements ShouldBeUnique, ShouldQueue
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
        return 'inv_export_'.$this->exportTask->requested_by.'_'.md5(json_encode($this->exportTask->parameters));
    }

    public function handle(): void
    {
        if (empty($this->tenantId)) {
            throw new \RuntimeException('Tenant ID is required to process inventory export job.');
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

        ini_set('memory_limit', '512M');

        try {
            $params = $this->exportTask->parameters ?? [];
            $start = Carbon::parse($params['start'] ?? now()->startOfMonth());
            $end = Carbon::parse($params['end'] ?? now());
            $itemType = (string) ($params['item_type'] ?? 'all');
            $itemId = isset($params['item_id']) ? (int) $params['item_id'] : null;

            $dir = "exports/tenant_{$this->tenantId}";
            Storage::disk('local')->makeDirectory($dir);
            $filename = $dir.'/inventory_ledger_'.$start->format('Ymd').'-'.$end->format('Ymd').'_'.$this->exportTask->id.'.xlsx';

            Excel::store(
                new InventoryLedgerExport(
                    startDate: $start,
                    endDate: $end,
                    storeId: $this->storeId,
                    itemType: $itemType,
                    itemId: $itemId
                ),
                $filename,
                'local'
            );

            $fullPath = Storage::disk('local')->path($filename);

            $fileSize = Storage::disk('local')->exists($filename) ? Storage::disk('local')->size($filename) : (file_exists($fullPath) ? filesize($fullPath) : null);

            $this->exportTask->update([
                'status' => ExportTaskStatus::Completed,
                'file_path' => $filename,
                'file_size_bytes' => $fileSize,
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $this->exportTask->update([
                'status' => ExportTaskStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->exportTask->update([
            'status' => ExportTaskStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Job timed out or failed.',
        ]);
    }
}
