<?php

declare(strict_types=1);

// [OMEGA-NODE5] Ekspor laporan besar tanpa memblokir request HTTP. | 2026-09-23

namespace App\Jobs;

use App\Enums\ExportTaskStatus;
use App\Exports\SalesExport;
use App\Models\ExportTask;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessSalesReportExportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public ExportTask $exportTask) {}

    public function uniqueId(): string
    {
        return $this->exportTask->requested_by . '_' . md5(json_encode($this->exportTask->parameters));
    }

    public function handle(): void
    {
        if ($this->exportTask->status !== ExportTaskStatus::Pending) {
            return;
        }

        $this->exportTask->update(['status' => ExportTaskStatus::Processing]);

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
            $this->exportTask->update(['status' => ExportTaskStatus::Failed, 'error_message' => $e->getMessage()]);
        }
    }
}
