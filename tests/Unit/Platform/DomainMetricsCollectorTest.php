<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\Observability\DomainMetricsCollector;
use PHPUnit\Framework\TestCase;

class DomainMetricsCollectorTest extends TestCase
{
    private DomainMetricsCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new DomainMetricsCollector;
    }

    public function test_to_prometheus_text_formats_metrics_correctly(): void
    {
        $mockMetrics = [
            'outbox_pending_count' => 15,
            'outbox_dead_letter_count' => 2,
            'outbox_oldest_pending_age_seconds' => 45,
            'queue_failed_jobs_count' => 3,
        ];

        $output = $this->collector->toPrometheusText($mockMetrics);

        $this->assertStringContainsString('# HELP outbox_pending_count Number of pending financial outbox events', $output);
        $this->assertStringContainsString('# TYPE outbox_pending_count gauge', $output);
        $this->assertStringContainsString('outbox_pending_count 15', $output);

        $this->assertStringContainsString('# HELP outbox_dead_letter_count Number of dead-lettered financial outbox events', $output);
        $this->assertStringContainsString('# TYPE outbox_dead_letter_count gauge', $output);
        $this->assertStringContainsString('outbox_dead_letter_count 2', $output);

        $this->assertStringContainsString('# HELP outbox_oldest_pending_age_seconds Age in seconds of the oldest pending financial outbox event', $output);
        $this->assertStringContainsString('# TYPE outbox_oldest_pending_age_seconds gauge', $output);
        $this->assertStringContainsString('outbox_oldest_pending_age_seconds 45', $output);

        $this->assertStringContainsString('# HELP queue_failed_jobs_count Total number of failed queue jobs', $output);
        $this->assertStringContainsString('# TYPE queue_failed_jobs_count counter', $output);
        $this->assertStringContainsString('queue_failed_jobs_count 3', $output);
    }

    public function test_collect_returns_expected_structure_and_types(): void
    {
        $metrics = $this->collector->collect();

        $this->assertArrayHasKey('outbox_pending_count', $metrics);
        $this->assertArrayHasKey('outbox_dead_letter_count', $metrics);
        $this->assertArrayHasKey('outbox_oldest_pending_age_seconds', $metrics);
        $this->assertArrayHasKey('queue_failed_jobs_count', $metrics);

        $this->assertIsInt($metrics['outbox_pending_count']);
        $this->assertIsInt($metrics['outbox_dead_letter_count']);
        $this->assertIsInt($metrics['outbox_oldest_pending_age_seconds']);
        $this->assertIsInt($metrics['queue_failed_jobs_count']);
    }

    public function test_to_prometheus_text_default_execution(): void
    {
        $output = $this->collector->toPrometheusText();

        $this->assertStringContainsString('outbox_pending_count', $output);
        $this->assertStringContainsString('outbox_dead_letter_count', $output);
        $this->assertStringContainsString('outbox_oldest_pending_age_seconds', $output);
        $this->assertStringContainsString('queue_failed_jobs_count', $output);
    }
}
