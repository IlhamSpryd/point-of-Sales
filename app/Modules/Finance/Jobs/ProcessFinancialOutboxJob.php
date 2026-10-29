<?php

declare(strict_types=1);

namespace App\Modules\Finance\Jobs;

use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Services\PostingEngineService;
use App\Services\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessFinancialOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(
        public int $outboxId
    ) {}

    public function handle(
        PostingEngineService $postingEngine,
        TenantContext $tenantContext
    ): void {
        $outbox = DB::transaction(function () {
            return FinancialEventOutbox::withoutTenantScope()
                ->where('id', $this->outboxId)
                ->lockForUpdate()
                ->first();
        });

        if (! $outbox) {
            return;
        }

        if ($outbox->status === 'PROCESSED') {
            return;
        }

        try {
            $tenantContext->runAs((int) $outbox->tenant_id, $outbox->store_id ? (int) $outbox->store_id : null, function () use ($postingEngine, $outbox) {
                $outbox->update(['status' => 'PROCESSING']);
                $postingEngine->processOutboxEvent($outbox);
            });
        } catch (\Throwable $e) {
            $newRetryCount = $outbox->retry_count + 1;
            $newStatus = ($newRetryCount >= $this->tries) ? 'DEAD_LETTER' : 'FAILED';
            $nextRetry = now()->addSeconds($this->backoff * $newRetryCount);

            $outbox->update([
                'status' => $newStatus,
                'retry_count' => $newRetryCount,
                'next_retry_at' => $nextRetry,
                'last_error' => substr($e->getMessage()."\n".$e->getTraceAsString(), 0, 1000),
            ]);

            Log::error("Gagal memproses financial outbox #{$outbox->id}: ".$e->getMessage(), [
                'outbox_id' => $outbox->id,
                'event_type' => $outbox->event_type,
            ]);

            if (config('queue.default') !== 'sync') {
                throw $e;
            }
        }
    }
}
