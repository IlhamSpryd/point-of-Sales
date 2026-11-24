<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExportTaskStatus;
use App\Modules\Reporting\Models\ExportTask;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneExportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'exports:prune {--days=7 : Usia file ekspor dalam hari sebelum dipangkas}';

    /**
     * The console command description.
     */
    protected $description = 'Pangkas file ekspor lawas yang telah melebihi batas retensi dan tandai task sebagai Expired';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);

        $this->info("Memangkas file ekspor yang dibuat sebelum {$cutoff->toDateTimeString()} ({$days} hari)...");

        $tasks = ExportTask::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', [ExportTaskStatus::Completed, ExportTaskStatus::Failed])
            ->get();

        $prunedCount = 0;

        foreach ($tasks as $task) {
            $filePath = $task->file_path;
            if ($filePath !== null && Storage::disk('local')->exists($filePath)) {
                try {
                    Storage::disk('local')->delete($filePath);
                } catch (\Throwable $e) {
                    Log::warning("[EXPORTS PRUNE] Gagal menghapus file fisik {$filePath}: {$e->getMessage()}");
                }
            }

            $task->update([
                'status' => ExportTaskStatus::Expired,
                'file_path' => null,
            ]);

            $prunedCount++;
        }

        $this->info("Pemangkasan selesai. {$prunedCount} task ekspor lawas telah ditandai Expired dan file fisiknya dihapus.");

        return Command::SUCCESS;
    }
}
