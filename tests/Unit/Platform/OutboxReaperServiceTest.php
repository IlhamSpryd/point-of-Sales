<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Finance\Enums\OutboxStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Platform\Outbox\OutboxReaperService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxReaperServiceTest extends TestCase
{
    use DatabaseTransactions;

    private OutboxReaperService $reaper;

    private int $tenantId;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->reaper = new OutboxReaperService(maxRetries: 5);
        $this->tenantId = (int) app(\App\Core\Context\TenantContext::class)->requireTenantId();
    }

    public function test_reaps_stuck_processing_row_back_to_pending(): void
    {
        $outbox = FinancialEventOutbox::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => 'evt_'.Str::random(12),
            'event_type' => 'test.reaper.stuck',
            'source_module' => 'sales',
            'source_type' => 'order',
            'source_id' => 101,
            'idempotency_key' => 'idemp_'.Str::random(16),
            'payload' => ['sample' => true],
            'status' => OutboxStatusEnum::PROCESSING,
            'occurred_at' => now()->subMinutes(15),
            'retry_count' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('financial_event_outboxes')
            ->where('id', $outbox->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $reapedCount = $this->reaper->reapStuckProcessingRows(staleMinutes: 5);

        $this->assertGreaterThanOrEqual(1, $reapedCount);

        $refreshed = FinancialEventOutbox::withoutTenantScope()->findOrFail($outbox->id);
        $this->assertSame(OutboxStatusEnum::PENDING, $refreshed->status);
        $this->assertSame(2, $refreshed->retry_count);
        $this->assertStringContainsString('Reaped by OutboxReaperService', (string) $refreshed->last_error);
    }

    public function test_moves_to_dead_letter_when_max_retries_exceeded(): void
    {
        $outbox = FinancialEventOutbox::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => 'evt_'.Str::random(12),
            'event_type' => 'test.reaper.dead_letter',
            'source_module' => 'finance',
            'source_type' => 'journal',
            'source_id' => 102,
            'idempotency_key' => 'idemp_'.Str::random(16),
            'payload' => ['sample' => true],
            'status' => OutboxStatusEnum::PROCESSING,
            'occurred_at' => now()->subMinutes(20),
            'retry_count' => 4, // 4 + 1 = 5, which reaches maxRetries 5
        ]);

        \Illuminate\Support\Facades\DB::table('financial_event_outboxes')
            ->where('id', $outbox->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $reapedCount = $this->reaper->reapStuckProcessingRows(staleMinutes: 5);

        $this->assertGreaterThanOrEqual(1, $reapedCount);

        $refreshed = FinancialEventOutbox::withoutTenantScope()->findOrFail($outbox->id);
        $this->assertSame(OutboxStatusEnum::DEAD_LETTER, $refreshed->status);
        $this->assertSame(5, $refreshed->retry_count);
    }

    public function test_does_not_reap_fresh_processing_rows(): void
    {
        $outbox = FinancialEventOutbox::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => 'evt_'.Str::random(12),
            'event_type' => 'test.reaper.fresh',
            'source_module' => 'sales',
            'source_type' => 'order',
            'source_id' => 103,
            'idempotency_key' => 'idemp_'.Str::random(16),
            'payload' => ['sample' => true],
            'status' => OutboxStatusEnum::PROCESSING,
            'occurred_at' => now(),
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now()->subMinutes(1), // only 1 minute old
        ]);

        $this->reaper->reapStuckProcessingRows(staleMinutes: 5);

        $refreshed = FinancialEventOutbox::withoutTenantScope()->findOrFail($outbox->id);
        $this->assertSame(OutboxStatusEnum::PROCESSING, $refreshed->status);
        $this->assertSame(0, $refreshed->retry_count);
    }

    public function test_reap_stuck_outbox_artisan_command_executes_cleanly(): void
    {
        $this->artisan('outbox:reap-stuck', ['--minutes' => 5])
            ->expectsOutputToContain('Scanning for outbox rows stuck in PROCESSING')
            ->expectsOutputToContain('Successfully reaped')
            ->assertSuccessful();
    }

    public function test_reap_stuck_outbox_artisan_command_rejects_non_positive_minutes(): void
    {
        $this->artisan('outbox:reap-stuck', ['--minutes' => 0])
            ->expectsOutputToContain('The --minutes option must be a positive integer greater than zero.')
            ->assertFailed();

        $this->artisan('outbox:reap-stuck', ['--minutes' => -10])
            ->expectsOutputToContain('The --minutes option must be a positive integer greater than zero.')
            ->assertFailed();
    }
}
