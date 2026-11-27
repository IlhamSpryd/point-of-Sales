<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Modules\Finance\Enums\OutboxStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Sales\Models\PaymentInbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service to safely detect and reap stuck PROCESSING outbox rows (OPS-04, ADR-107).
 * Prevents deadlocks and zombie jobs when workers die or experience OOM.
 */
class OutboxReaperService
{
    public const DEFAULT_MAX_RETRIES = 5;

    public function __construct(
        protected int $maxRetries = self::DEFAULT_MAX_RETRIES
    ) {}

    /**
     * Resets stuck PROCESSING rows older than $staleMinutes back to PENDING,
     * or transitions to DEAD_LETTER if maximum retry attempts are exhausted.
     *
     * @param  int  $staleMinutes  Threshold in minutes to consider a processing row stuck
     * @return int Number of reaped rows
     */
    public function reapStuckProcessingRows(int $staleMinutes = 5): int
    {
        $cutoff = Carbon::now()->subMinutes($staleMinutes);

        return DB::transaction(function () use ($cutoff, $staleMinutes) {
            // @tenant-bypass: background_job
            $stuckRows = FinancialEventOutbox::withoutTenantScope()
                ->where('status', OutboxStatusEnum::PROCESSING->value)
                ->where('updated_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $reapedCount = 0;

            foreach ($stuckRows as $outbox) {
                $newRetryCount = ((int) $outbox->retry_count) + 1;
                $isDeadLetter = $newRetryCount >= $this->maxRetries;

                $newStatus = $isDeadLetter
                    ? OutboxStatusEnum::DEAD_LETTER
                    : OutboxStatusEnum::PENDING;

                $reapReason = "Reaped by OutboxReaperService: stuck in PROCESSING for >= {$staleMinutes} minutes.";
                $existingError = $outbox->last_error ? $outbox->last_error."\n" : '';

                $outbox->update([
                    'status' => $newStatus,
                    'retry_count' => $newRetryCount,
                    'last_error' => substr($existingError.$reapReason, 0, 1000),
                ]);

                Log::warning("[OUTBOX-REAPER] Outbox #{$outbox->id} transitioned to {$newStatus->value} (attempt {$newRetryCount})", [
                    'outbox_id' => $outbox->id,
                    'event_type' => $outbox->event_type,
                    'tenant_id' => $outbox->tenant_id,
                ]);

                $reapedCount++;
            }

            return $reapedCount;
        });
    }

    /**
     * Reaps stuck PROCESSING payment_inbox rows back to FAILED for retry (BE-CONC-01).
     *
     * @param  int  $staleMinutes  Threshold in minutes to consider a processing row stuck
     * @return int Number of reaped rows
     */
    public function reapStuckPaymentInboxes(int $staleMinutes = 5): int
    {
        $cutoff = Carbon::now()->subMinutes($staleMinutes);

        return DB::transaction(function () use ($cutoff, $staleMinutes) {
            // @tenant-bypass: background_job
            $stuckInboxes = PaymentInbox::withoutGlobalScopes()
                ->where('status', 'processing')
                ->where('updated_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $reaped = 0;
            foreach ($stuckInboxes as $inbox) {
                $inbox->update([
                    'status' => 'failed',
                    'error_message' => "Processing timeout / worker killed; reaped after >= {$staleMinutes} minutes for retry.",
                ]);

                Log::warning("[PAYMENT-INBOX-REAPER] PaymentInbox #{$inbox->id} transitioned to failed for retry", [
                    'inbox_id' => $inbox->id,
                    'tenant_id' => $inbox->tenant_id,
                    'provider' => $inbox->provider,
                    'idempotency_key' => $inbox->idempotency_key,
                ]);

                $reaped++;
            }

            return $reaped;
        });
    }
}
