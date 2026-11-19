<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Domain Metrics Collector for Prometheus operational observability (OPS-02, ENT-02).
 */
class DomainMetricsCollector
{
    /**
     * Collects all core operational gauges and counters.
     *
     * @return array{
     *     apeiron_orders_completed_24h_total: int,
     *     apeiron_orders_voided_24h_total: int,
     *     apeiron_outbox_pending_count: int,
     *     apeiron_outbox_dead_letter_total: int,
     *     apeiron_outbox_oldest_pending_age_seconds: int,
     *     apeiron_queue_failed_jobs_total: int,
     *     apeiron_webhook_delivery_failure_ratio_15m: float,
     *     apeiron_storage_free_percent: float,
     *     apeiron_active_tenants_total: int,
     *     outbox_pending_count: int,
     *     outbox_dead_letter_count: int,
     *     outbox_oldest_pending_age_seconds: int,
     *     queue_failed_jobs_count: int
     * }
     */
    public function collect(): array
    {
        $ordersCompleted24h = 0;
        $ordersVoided24h = 0;
        $outboxPendingCount = 0;
        $outboxDeadLetterTotal = 0;
        $outboxOldestPendingAgeSeconds = 0;
        $queueFailedJobsTotal = 0;
        $webhookFailureRatio15m = 0.0;
        $storageFreePercent = 100.0;
        $activeTenantsTotal = 0;

        try {
            if (Schema::hasTable('orders')) {
                $since24h = Carbon::now()->subDay();
                $ordersCompleted24h = (int) DB::table('orders')
                    ->whereIn('order_status', ['completed', 'paid'])
                    ->where('created_at', '>=', $since24h)
                    ->count();

                $ordersVoided24h = (int) DB::table('orders')
                    ->where(function ($q) {
                        $q->where('order_status', 'void')
                            ->orWhereNotNull('voided_at');
                    })
                    ->where('created_at', '>=', $since24h)
                    ->count();
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        try {
            if (Schema::hasTable('financial_event_outboxes')) {
                $outboxPendingCount = (int) DB::table('financial_event_outboxes')
                    ->where('status', 'PENDING')
                    ->count();

                $outboxDeadLetterTotal = (int) DB::table('financial_event_outboxes')
                    ->where('status', 'DEAD_LETTER')
                    ->count();

                $oldestPending = DB::table('financial_event_outboxes')
                    ->where('status', 'PENDING')
                    ->min('created_at');

                if ($oldestPending !== null) {
                    $outboxOldestPendingAgeSeconds = max(0, Carbon::now()->diffInSeconds(Carbon::parse($oldestPending)));
                }
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        try {
            if (Schema::hasTable('failed_jobs')) {
                $queueFailedJobsTotal = (int) DB::table('failed_jobs')->count();
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        try {
            if (Schema::hasTable('webhook_delivery_logs')) {
                $since15m = Carbon::now()->subMinutes(15);
                $totalWebhooks = (int) DB::table('webhook_delivery_logs')
                    ->where('created_at', '>=', $since15m)
                    ->count();

                if ($totalWebhooks > 0) {
                    $failedWebhooks = (int) DB::table('webhook_delivery_logs')
                        ->where('created_at', '>=', $since15m)
                        ->where('status', 'failed')
                        ->count();
                    $webhookFailureRatio15m = round($failedWebhooks / $totalWebhooks, 4);
                }
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        try {
            $diskPath = base_path();
            $totalBytes = @disk_total_space($diskPath);
            $freeBytes = @disk_free_space($diskPath);
            if ($totalBytes !== false && $totalBytes > 0 && $freeBytes !== false) {
                $storageFreePercent = round(($freeBytes / $totalBytes) * 100, 2);
            }
        } catch (\Throwable) {
            // Fail safe on storage probe errors
        }

        try {
            if (Schema::hasTable('tenants')) {
                $activeTenantsTotal = (int) DB::table('tenants')
                    ->where('status', 'active')
                    ->count();
            }
        } catch (\Throwable) {
            // Fail safe on database errors
        }

        return [
            'apeiron_orders_completed_24h_total' => $ordersCompleted24h,
            'apeiron_orders_voided_24h_total' => $ordersVoided24h,
            'apeiron_outbox_pending_count' => $outboxPendingCount,
            'apeiron_outbox_dead_letter_total' => $outboxDeadLetterTotal,
            'apeiron_outbox_oldest_pending_age_seconds' => $outboxOldestPendingAgeSeconds,
            'apeiron_queue_failed_jobs_total' => $queueFailedJobsTotal,
            'apeiron_webhook_delivery_failure_ratio_15m' => $webhookFailureRatio15m,
            'apeiron_storage_free_percent' => $storageFreePercent,
            'apeiron_active_tenants_total' => $activeTenantsTotal,

            // Backward compatibility aliases
            'outbox_pending_count' => $outboxPendingCount,
            'outbox_dead_letter_count' => $outboxDeadLetterTotal,
            'outbox_oldest_pending_age_seconds' => $outboxOldestPendingAgeSeconds,
            'queue_failed_jobs_count' => $queueFailedJobsTotal,
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

        $lines[] = '# HELP apeiron_orders_completed_24h_total Total completed or paid POS orders in the last 24 hours';
        $lines[] = '# TYPE apeiron_orders_completed_24h_total counter';
        $lines[] = 'apeiron_orders_completed_24h_total '.($data['apeiron_orders_completed_24h_total'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP apeiron_orders_voided_24h_total Total voided POS orders in the last 24 hours';
        $lines[] = '# TYPE apeiron_orders_voided_24h_total counter';
        $lines[] = 'apeiron_orders_voided_24h_total '.($data['apeiron_orders_voided_24h_total'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP apeiron_outbox_pending_count Number of pending financial outbox events awaiting dispatch';
        $lines[] = '# TYPE apeiron_outbox_pending_count gauge';
        $lines[] = 'apeiron_outbox_pending_count '.($data['apeiron_outbox_pending_count'] ?? $data['outbox_pending_count'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP apeiron_outbox_dead_letter_total Total dead-lettered financial outbox events';
        $lines[] = '# TYPE apeiron_outbox_dead_letter_total counter';
        $lines[] = 'apeiron_outbox_dead_letter_total '.($data['apeiron_outbox_dead_letter_total'] ?? $data['outbox_dead_letter_count'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP apeiron_outbox_oldest_pending_age_seconds Age in seconds of the oldest pending financial outbox event';
        $lines[] = '# TYPE apeiron_outbox_oldest_pending_age_seconds gauge';
        $lines[] = 'apeiron_outbox_oldest_pending_age_seconds '.($data['apeiron_outbox_oldest_pending_age_seconds'] ?? $data['outbox_oldest_pending_age_seconds'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP apeiron_queue_failed_jobs_total Total number of failed queue jobs';
        $lines[] = '# TYPE apeiron_queue_failed_jobs_total counter';
        $lines[] = 'apeiron_queue_failed_jobs_total '.($data['apeiron_queue_failed_jobs_total'] ?? $data['queue_failed_jobs_count'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP apeiron_webhook_delivery_failure_ratio_15m Outbound webhook delivery failure ratio over the last 15 minutes';
        $lines[] = '# TYPE apeiron_webhook_delivery_failure_ratio_15m gauge';
        $lines[] = 'apeiron_webhook_delivery_failure_ratio_15m '.sprintf('%.4f', (float) ($data['apeiron_webhook_delivery_failure_ratio_15m'] ?? 0.0));
        $lines[] = '';

        $lines[] = '# HELP apeiron_storage_free_percent Percentage of available free disk storage';
        $lines[] = '# TYPE apeiron_storage_free_percent gauge';
        $lines[] = 'apeiron_storage_free_percent '.sprintf('%.2f', (float) ($data['apeiron_storage_free_percent'] ?? 100.0));
        $lines[] = '';

        $lines[] = '# HELP apeiron_active_tenants_total Total active provisioned tenants';
        $lines[] = '# TYPE apeiron_active_tenants_total gauge';
        $lines[] = 'apeiron_active_tenants_total '.($data['apeiron_active_tenants_total'] ?? 0);
        $lines[] = '';

        // Backward compatibility metrics
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
