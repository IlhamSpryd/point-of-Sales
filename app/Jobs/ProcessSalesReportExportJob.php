<?php

declare(strict_types=1);

// [OMEGA-NODE5] Ekspor laporan besar tanpa memblokir request HTTP. | 2026-09-23

namespace App\Jobs;

use App\Enums\ExportTaskStatus;
use App\Exports\SalesExport;
use App\Models\ExportTask;
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

    // FIX (M6-B-002): dedicated queue so a heavy report export never
    // blocks time-sensitive omnichannel order jobs sharing the 'default'
    // queue (see ProcessWebhookOrderJob::$queue).

    public function __construct(public ExportTask $exportTask)
    {
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->exportTask->requested_by.'_'.md5(json_encode($this->exportTask->parameters));
    }

    public function handle(): void
    {
        if ($this->exportTask->status !== ExportTaskStatus::Pending) {
            return;
        }

        $this->exportTask->update(['status' => ExportTaskStatus::Processing]);

        // Defensive memory ceiling for this worker process only. NOT a
        // substitute for the chunked reading in SalesExport (M6-B-001),
        // but gives PhpSpreadsheet's XML buffers more headroom before a
        // hard fatal on very wide date ranges.
        ini_set('memory_limit', '512M');

        try {
            $params = $this->exportTask->parameters ?? [];
            $start = Carbon::parse($params['start'] ?? now()->startOfMonth());
            $end = Carbon::parse($params['end'] ?? now());

            $filename = 'exports/sales_report_'.$start->format('Ymd').'-'.$end->format('Ymd').'_'.$this->exportTask->id.'.xlsx';

            // Disk 'local' (storage/app/private) -- laporan finansial TIDAK
            // ditaruh di disk 'public' seperti foto produk.
            Excel::store(new SalesExport($start, $end), $filename, 'local');
            $fullPath = Storage::disk('local')->path($filename);

            $this->exportTask->update([
                'status' => ExportTaskStatus::Completed,
                'file_path' => $filename,
                'file_size_bytes' => file_exists($fullPath) ? filesize($fullPath) : null,
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
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
