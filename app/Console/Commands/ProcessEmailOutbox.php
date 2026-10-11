<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProvisioningOutboxJob;
use App\Models\EmailOutbox;
use Illuminate\Console\Command;

/**
 * Domain 1 — processor transactional outbox.
 * Men-pick baris pending yang next_attempt_at sudah tercapai (atau NULL)
 * dan dispatch job per baris. Fallback safety net bila dispatch after-commit
 * gagal (crash antara commit dan enqueue).
 */
class ProcessEmailOutbox extends Command
{
    protected $signature = 'emails:process-outbox {--limit=50}';

    protected $description = 'Kirim email dari transactional outbox (retry pending, aman idempotent)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Temukan item:
        // 1. PENDING yang sudah jatuh tempo (atau baru dibuat tanpa jadwal)
        // 2. PROCESSING yang lease worker-nya kedaluwarsa (worker mati/crash)
        $due = EmailOutbox::query()
            ->where(function ($q) {
                $q->where(function ($pending) {
                    $pending->where('status', EmailOutbox::STATUS_PENDING)
                        ->where(function ($time) {
                            $time->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($staleProcessing) {
                    $staleProcessing->where('status', EmailOutbox::STATUS_PROCESSING)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', now());
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $id) {
            ProcessProvisioningOutboxJob::dispatch($id);
        }

        $this->info("Dispatched {$due->count()} outbox email(s).");

        return self::SUCCESS;
    }
}
