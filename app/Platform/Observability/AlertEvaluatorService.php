<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Alert-as-Code Engine for Automated Operational Incident Detection (OPS-03, ENT-02).
 */
class AlertEvaluatorService
{
    public function __construct(
        private readonly DomainMetricsCollector $metricsCollector
    ) {}

    /**
     * Evaluates all configured operational alert rules against collected metrics.
     *
     * @param  array<string, int|float>|null  $overrideMetrics
     * @return list<array{
     *     code: string,
     *     severity: string,
     *     metric: string,
     *     current_value: int|float,
     *     threshold: int|float,
     *     runbook: string,
     *     message: string
     * }>
     */
    public function evaluate(?array $overrideMetrics = null): array
    {
        $metrics = $overrideMetrics ?? $this->metricsCollector->collect();
        $thresholds = (array) config('observability.thresholds', []);
        $alerts = [];

        // 1. Outbox Pending Age (Integrity SLA)
        $outboxAgeThreshold = (int) ($thresholds['outbox_pending_age_seconds'] ?? 300);
        $currentOutboxAge = (int) ($metrics['apeiron_outbox_oldest_pending_age_seconds'] ?? $metrics['outbox_oldest_pending_age_seconds'] ?? 0);
        if ($currentOutboxAge > $outboxAgeThreshold) {
            $alerts[] = [
                'code' => 'OUTBOX_PENDING_LAG',
                'severity' => 'critical',
                'metric' => 'apeiron_outbox_oldest_pending_age_seconds',
                'current_value' => $currentOutboxAge,
                'threshold' => $outboxAgeThreshold,
                'runbook' => 'docs/14-enterprise-architecture/TARGET_SYSTEM_ARCHITECTURE.md §8.4 — Cek outbox reaper & subledger worker',
                'message' => "Oldest pending outbox event age is {$currentOutboxAge}s (threshold: {$outboxAgeThreshold}s)",
            ];
        }

        // 2. Outbox Dead Letter Count (Zero Dead-Letter Policy)
        $outboxDeadLetterThreshold = (int) ($thresholds['outbox_dead_letter_max'] ?? 0);
        $currentDeadLetters = (int) ($metrics['apeiron_outbox_dead_letter_total'] ?? $metrics['outbox_dead_letter_count'] ?? 0);
        if ($currentDeadLetters > $outboxDeadLetterThreshold) {
            $alerts[] = [
                'code' => 'OUTBOX_DEAD_LETTER_DETECTED',
                'severity' => 'critical',
                'metric' => 'apeiron_outbox_dead_letter_total',
                'current_value' => $currentDeadLetters,
                'threshold' => $outboxDeadLetterThreshold,
                'runbook' => 'docs/14-enterprise-architecture/TARGET_SYSTEM_ARCHITECTURE.md §8.4 — Cek financial_event_outboxes dead letter queue',
                'message' => "Dead-lettered financial outbox events detected: {$currentDeadLetters} (threshold: {$outboxDeadLetterThreshold})",
            ];
        }

        // 3. Failed Queue Jobs
        $queueFailedThreshold = (int) ($thresholds['queue_failed_jobs_max'] ?? 10);
        $currentQueueFailed = (int) ($metrics['apeiron_queue_failed_jobs_total'] ?? $metrics['queue_failed_jobs_count'] ?? 0);
        if ($currentQueueFailed > $queueFailedThreshold) {
            $alerts[] = [
                'code' => 'QUEUE_FAILED_JOBS_HIGH',
                'severity' => 'warning',
                'metric' => 'apeiron_queue_failed_jobs_total',
                'current_value' => $currentQueueFailed,
                'threshold' => $queueFailedThreshold,
                'runbook' => 'docs/14-enterprise-architecture/TARGET_SYSTEM_ARCHITECTURE.md §8.4 — Cek failed_jobs table dan queue worker logs',
                'message' => "Failed queue jobs count reached {$currentQueueFailed} (threshold: {$queueFailedThreshold})",
            ];
        }

        // 4. Outbound Webhook Delivery Failure Ratio over 15 minutes
        $webhookRatioThreshold = (float) ($thresholds['webhook_failure_ratio_max'] ?? 0.05);
        $currentWebhookRatio = (float) ($metrics['apeiron_webhook_delivery_failure_ratio_15m'] ?? 0.0);
        if ($currentWebhookRatio > $webhookRatioThreshold) {
            $alerts[] = [
                'code' => 'WEBHOOK_DELIVERY_FAILURE_SPIKE',
                'severity' => 'warning',
                'metric' => 'apeiron_webhook_delivery_failure_ratio_15m',
                'current_value' => $currentWebhookRatio,
                'threshold' => $webhookRatioThreshold,
                'runbook' => 'docs/14-enterprise-architecture/INTEGRATION_AND_EVENT_ARCHITECTURE.md §9 — Cek webhook_delivery_logs & partner endpoint health',
                'message' => "Outbound webhook delivery failure ratio over 15m is {$currentWebhookRatio} (threshold: {$webhookRatioThreshold})",
            ];
        }

        // 5. Disk Storage Low
        $diskFreeMinThreshold = (float) ($thresholds['disk_free_min_percent'] ?? 15.0);
        $currentDiskFree = (float) ($metrics['apeiron_storage_free_percent'] ?? 100.0);
        if ($currentDiskFree < $diskFreeMinThreshold) {
            $alerts[] = [
                'code' => 'DISK_STORAGE_LOW',
                'severity' => 'critical',
                'metric' => 'apeiron_storage_free_percent',
                'current_value' => $currentDiskFree,
                'threshold' => $diskFreeMinThreshold,
                'runbook' => 'docs/14-enterprise-architecture/TARGET_SYSTEM_ARCHITECTURE.md §8.4 — Bersihkan log usang, docker prune, atau perluas disk',
                'message' => "Available free disk space is {$currentDiskFree}% (minimum threshold: {$diskFreeMinThreshold}%)",
            ];
        }

        return $alerts;
    }

    /**
     * Evaluates alert rules, deduplicates against cache cooldown, logs breaches, and dispatches webhooks.
     *
     * @param  array<string, int|float>|null  $overrideMetrics
     * @return array{
     *     breaching: list<array{code: string, severity: string, metric: string, current_value: int|float, threshold: int|float, runbook: string, message: string}>,
     *     dispatched: list<array{code: string, severity: string, metric: string, current_value: int|float, threshold: int|float, runbook: string, message: string}>,
     *     suppressed: list<array{code: string, severity: string, metric: string, current_value: int|float, threshold: int|float, runbook: string, message: string}>
     * }
     */
    public function evaluateAndDispatch(?array $overrideMetrics = null): array
    {
        $breaching = $this->evaluate($overrideMetrics);
        $cooldownMinutes = (int) config('observability.alert_cooldown_minutes', 15);
        $webhookUrl = config('observability.alert_webhook_url');

        $dispatched = [];
        $suppressed = [];

        foreach ($breaching as $alert) {
            $cacheKey = "ops:alert:cooldown:{$alert['code']}";

            if (Cache::has($cacheKey)) {
                $suppressed[] = $alert;

                continue;
            }

            // Set cooldown in cache
            Cache::put($cacheKey, now()->toIso8601String(), now()->addMinutes($cooldownMinutes));

            // Structured logging
            $logContext = [
                'code' => $alert['code'],
                'severity' => $alert['severity'],
                'metric' => $alert['metric'],
                'current_value' => $alert['current_value'],
                'threshold' => $alert['threshold'],
                'runbook' => $alert['runbook'],
            ];

            if ($alert['severity'] === 'critical') {
                Log::critical("[Apeiron Ops Alert] {$alert['message']}", $logContext);
            } else {
                Log::warning("[Apeiron Ops Alert] {$alert['message']}", $logContext);
            }

            $dispatched[] = $alert;
        }

        // Dispatch webhook if webhook URL configured and alerts dispatched
        if (! empty($webhookUrl) && ! empty($dispatched)) {
            $this->dispatchWebhook((string) $webhookUrl, $dispatched);
        }

        return [
            'breaching' => $breaching,
            'dispatched' => $dispatched,
            'suppressed' => $suppressed,
        ];
    }

    /**
     * Dispatches structured alert notification to configured webhook.
     *
     * @param  list<array{code: string, severity: string, metric: string, current_value: int|float, threshold: int|float, runbook: string, message: string}>  $alerts
     */
    private function dispatchWebhook(string $webhookUrl, array $alerts): void
    {
        try {
            $summaryLines = array_map(
                fn ($a) => "[{$a['severity']}] {$a['code']}: {$a['message']} (Runbook: {$a['runbook']})",
                $alerts
            );

            $formattedMessage = "🚨 *Apeiron Operational Alert Notification*\n".implode("\n", $summaryLines);

            $payload = [
                'system' => 'Apeiron SaaS POS',
                'environment' => config('app.env', 'production'),
                'timestamp' => now()->toIso8601String(),
                'text' => $formattedMessage,
                'content' => $formattedMessage,
                'alerts' => $alerts,
            ];

            Http::timeout(5)->post($webhookUrl, $payload);
        } catch (\Throwable $e) {
            Log::error('[Apeiron Ops Alert] Failed to dispatch webhook alert', [
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatches a safe test notification ping to verify webhook connectivity without evaluating metrics or setting cooldowns.
     */
    public function sendTestPing(?string $overrideWebhookUrl = null): bool
    {
        $webhookUrl = $overrideWebhookUrl ?? config('observability.alert_webhook_url');

        if (empty($webhookUrl)) {
            Log::warning('[Apeiron Ops Alert] Webhook test ping skipped: no webhook URL configured.');

            return false;
        }

        $formattedMessage = "🧪 *[TEST] Apeiron Production SRE Alerting Active* — https://app.apeironsystem.web.id\n"
            ."Status: Systems Operational\n"
            .'Timestamp: '.now()->toIso8601String();

        $payload = [
            'system' => 'Apeiron SaaS POS',
            'environment' => config('app.env', 'production'),
            'timestamp' => now()->toIso8601String(),
            'text' => $formattedMessage,
            'content' => $formattedMessage,
            'is_test' => true,
        ];

        try {
            $response = Http::timeout(5)->post((string) $webhookUrl, $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[Apeiron Ops Alert] Failed to dispatch test webhook ping', [
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
