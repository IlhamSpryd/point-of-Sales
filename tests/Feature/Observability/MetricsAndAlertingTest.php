<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Platform\Observability\AlertEvaluatorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetricsAndAlertingTest extends TestCase
{
    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        Cache::flush();
    }

    public function test_metrics_endpoint_rejects_missing_bearer_token(): void
    {
        Config::set('observability.metrics_token', 'super-secret-token-123');

        $response = $this->get('/metrics');

        $response->assertStatus(401);
        $this->assertStringContainsString('Missing or invalid Bearer token', $response->getContent() ?: '');
    }

    public function test_metrics_endpoint_rejects_invalid_bearer_token(): void
    {
        Config::set('observability.metrics_token', 'super-secret-token-123');

        $response = $this->withHeader('Authorization', 'Bearer wrong-token')->get('/metrics');

        $response->assertStatus(401);
        $this->assertStringContainsString('Invalid Bearer token', $response->getContent() ?: '');
    }

    public function test_metrics_endpoint_fails_closed_when_token_is_unconfigured(): void
    {
        Config::set('observability.metrics_token', '');

        $response = $this->withHeader('Authorization', 'Bearer any-token')->get('/metrics');

        $response->assertStatus(401);
        $this->assertStringContainsString('Scrape token not configured', $response->getContent() ?: '');
    }

    public function test_metrics_endpoint_returns_prometheus_text_with_valid_token(): void
    {
        Config::set('observability.metrics_token', 'valid-prometheus-scrape-token');

        $response = $this->withHeader('Authorization', 'Bearer valid-prometheus-scrape-token')
            ->get('/metrics');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        $content = $response->getContent() ?: '';
        $this->assertStringContainsString('# HELP apeiron_outbox_pending_count', $content);
        $this->assertStringContainsString('# TYPE apeiron_outbox_pending_count gauge', $content);
        $this->assertStringContainsString('apeiron_outbox_pending_count', $content);

        $this->assertStringContainsString('# HELP apeiron_outbox_dead_letter_total', $content);
        $this->assertStringContainsString('apeiron_outbox_dead_letter_total', $content);

        $this->assertStringContainsString('# HELP apeiron_orders_completed_24h_total', $content);
        $this->assertStringContainsString('# HELP apeiron_storage_free_percent', $content);
    }

    public function test_alert_evaluator_triggers_alerts_when_thresholds_breached(): void
    {
        Http::fake();

        Config::set('observability.alert_webhook_url', 'https://hooks.slack.com/services/test/alerts');
        Config::set('observability.alert_cooldown_minutes', 15);
        Config::set('observability.thresholds.outbox_pending_age_seconds', 300);
        Config::set('observability.thresholds.outbox_dead_letter_max', 0);
        Config::set('observability.thresholds.queue_failed_jobs_max', 10);
        Config::set('observability.thresholds.webhook_failure_ratio_max', 0.05);
        Config::set('observability.thresholds.disk_free_min_percent', 15.0);

        $mockMetrics = [
            'apeiron_outbox_oldest_pending_age_seconds' => 450, // Breach > 300
            'apeiron_outbox_dead_letter_total' => 2,            // Breach > 0
            'apeiron_queue_failed_jobs_total' => 15,           // Breach > 10
            'apeiron_webhook_delivery_failure_ratio_15m' => 0.12, // Breach > 0.05
            'apeiron_storage_free_percent' => 10.5,            // Breach < 15.0
        ];

        /** @var AlertEvaluatorService $service */
        $service = app(AlertEvaluatorService::class);
        $result = $service->evaluateAndDispatch($mockMetrics);

        $this->assertCount(5, $result['breaching']);
        $this->assertCount(5, $result['dispatched']);
        $this->assertCount(0, $result['suppressed']);

        $codes = array_column($result['breaching'], 'code');
        $this->assertContains('OUTBOX_PENDING_LAG', $codes);
        $this->assertContains('OUTBOX_DEAD_LETTER_DETECTED', $codes);
        $this->assertContains('QUEUE_FAILED_JOBS_HIGH', $codes);
        $this->assertContains('WEBHOOK_DELIVERY_FAILURE_SPIKE', $codes);
        $this->assertContains('DISK_STORAGE_LOW', $codes);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://hooks.slack.com/services/test/alerts'
                && isset($request['alerts'])
                && count($request['alerts']) === 5
                && str_contains($request['text'], 'Apeiron Operational Alert');
        });
    }

    public function test_alert_evaluator_respects_cooldown_deduplication(): void
    {
        Http::fake();

        Config::set('observability.alert_webhook_url', 'https://hooks.slack.com/services/test/alerts');
        Config::set('observability.alert_cooldown_minutes', 15);
        Config::set('observability.thresholds.outbox_dead_letter_max', 0);

        $mockMetrics = [
            'apeiron_outbox_dead_letter_total' => 3,
        ];

        /** @var AlertEvaluatorService $service */
        $service = app(AlertEvaluatorService::class);

        // First execution: dispatched
        $run1 = $service->evaluateAndDispatch($mockMetrics);
        $this->assertCount(1, $run1['dispatched']);
        $this->assertCount(0, $run1['suppressed']);
        Http::assertSentCount(1);

        // Second execution immediately after: should be suppressed by cooldown
        $run2 = $service->evaluateAndDispatch($mockMetrics);
        $this->assertCount(1, $run2['breaching']);
        $this->assertCount(0, $run2['dispatched']);
        $this->assertCount(1, $run2['suppressed']);
        $this->assertSame('OUTBOX_DEAD_LETTER_DETECTED', $run2['suppressed'][0]['code']);

        // Webhook should NOT have been sent a second time
        Http::assertSentCount(1);
    }

    public function test_ops_evaluate_alerts_console_command_runs_successfully(): void
    {
        Http::fake();

        $this->artisan('ops:evaluate-alerts')
            ->expectsOutputToContain('Evaluating operational alerts against live domain metrics')
            ->assertExitCode(0);
    }
}
