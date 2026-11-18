<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Domain Metrics Collector for Prometheus operational observability (OPS-02, ADR-107).
 */
class DomainMetricsCollector
{
    /**
     * Collects all core operational gauges and counters.
     *
     * @return array{
     *     outbox_pending_count: int,
     *     outbox_dead_letter_count: int,
     *     outbox_oldest_pending_age_seconds: int,
     *     queue_failed_jobs_count: int
     * }
     */
    public function collect(): array
    {
        $outboxPendingCount = 0;
        $outboxDeadLetterCount = 0;
        $oldestPendingAgeSeconds = 0;
        $queueFailedJobsCount = 0;

        try {
            if (Schema::hasTable('financial_event_outboxes')) {
                $outboxPendingCount = (int) DB::table('financial_event_outboxes')
                    ->where('status', 'PENDING')
                    ->count();

                $outboxDeadLetterCount = (int) DB::table('financial_event_outboxes')
                    ->where('status', 'DEAD_LETTER')
                    ->count();

                $oldestPendingTimestamp = DB::table('financial_event_outboxes')
                    ->where('status', 'PENDING')
                    ->min('created_at');

                if ($oldestPendingTimestamp !== null) {
                    $oldestPendingAgeSeconds = max(0, Carbon::now()->diffInSeconds(Carbon::parse($oldestPendingTimestamp)));
                }
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        try {
            if (Schema::hasTable('failed_jobs')) {
                $queueFailedJobsCount = (int) DB::table('failed_jobs')->count();
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        return [
            'outbox_pending_count' => $outboxPendingCount,
            'outbox_dead_letter_count' => $outboxDeadLetterCount,
            'outbox_oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
            'queue_failed_jobs_count' => $queueFailedJobsCount,
        ];
    }

    /**
     * Renders metrics in Prometheus standard text exposition format (0.0.4).
     *
     * @param  array<string, int|float>|null  $metrics  Optional pre-collected metrics array
     */
    public function toPrometheusText(?array $metrics = null): string
    {
        $data = $metrics ?? $this->collect();

        $lines = [];

        $lines[] = '# HELP outbox_pending_count Number of pending financial outbox events';
        $lines[] = '# TYPE outbox_pending_count gauge';
        $lines[] = 'outbox_pending_count '.($data['outbox_pending_count'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP outbox_dead_letter_count Number of dead-lettered financial outbox events';
        $lines[] = '# TYPE outbox_dead_letter_count gauge';
        $lines[] = 'outbox_dead_letter_count '.($data['outbox_dead_letter_count'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP outbox_oldest_pending_age_seconds Age in seconds of the oldest pending financial outbox event';
        $lines[] = '# TYPE outbox_oldest_pending_age_seconds gauge';
        $lines[] = 'outbox_oldest_pending_age_seconds '.($data['outbox_oldest_pending_age_seconds'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP queue_failed_jobs_count Total number of failed queue jobs';
        $lines[] = '# TYPE queue_failed_jobs_count counter';
        $lines[] = 'queue_failed_jobs_count '.($data['queue_failed_jobs_count'] ?? 0);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
