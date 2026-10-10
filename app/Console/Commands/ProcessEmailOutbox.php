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

        $due = EmailOutbox::query()
            ->where('status', EmailOutbox::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
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
