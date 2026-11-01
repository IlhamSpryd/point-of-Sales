<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\OutboxStatusEnum;
use App\Modules\Finance\Jobs\ProcessFinancialOutboxJob;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\PostingEngineService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class FinancialOutboxJobConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Outbox Concurrency Tenant',
            'slug' => 'outbox-concur-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Finance Outbox Store',
            'code' => 'OUT-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Periode Aktif',
            'period_code' => 'FY-'.now()->year.'-M'.now()->format('m'),
            'period_name' => 'FY-'.now()->year.'-M'.now()->format('m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => 'OPEN',
            'fiscal_year' => now()->year,
            'period_number' => (int) now()->format('m'),
            'is_closed' => false,
        ]);
    }

    public function test_financial_outbox_job_processes_pending_event_atomically(): void
    {
        $outbox = FinancialEventOutbox::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'event_id' => 'evt-'.uniqid(),
            'event_type' => 'pos.order.paid',
            'source_module' => 'SALES',
            'source_type' => 'ORDER',
            'source_id' => 101,
            'source_number' => 'ORD-101',
            'idempotency_key' => 'idemp-outbox-'.uniqid(),
            'payload' => [
                'order_id' => 101,
                'cash_amount' => '50000.00',
                'subtotal' => '50000.00',
                'cogs_retail' => '30000.00',
                'tax_amount' => '0.00',
                'discount_amount' => '0.00',
            ],
            'status' => OutboxStatusEnum::PENDING,
            'occurred_at' => now(),
        ]);

        $job = new ProcessFinancialOutboxJob($outbox->id);
        $job->handle(app(PostingEngineService::class), app(TenantContext::class));

        $fresh = $outbox->fresh();
        $this->assertNull($fresh->last_error, 'Last error was: '.($fresh->last_error ?? 'none'));
        $this->assertEquals(OutboxStatusEnum::PROCESSED, $fresh->status);
        $this->assertNotNull($fresh->journal_entry_id);
        $this->assertNotNull($fresh->processed_at);

        $journal = JournalEntry::find($fresh->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertSame($this->tenant->id, $journal->tenant_id);
    }

    public function test_concurrent_or_duplicate_job_invocation_is_idempotent_and_skips_processed_outbox(): void
    {
        $mockPostingEngine = Mockery::mock(PostingEngineService::class);

        $outbox = FinancialEventOutbox::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'event_id' => 'evt-'.uniqid(),
            'event_type' => 'POS_ORDER_PAID',
            'source_module' => 'SALES',
            'source_type' => 'ORDER',
            'source_id' => 202,
            'source_number' => 'ORD-202',
            'idempotency_key' => 'idemp-concur-'.uniqid(),
            'payload' => [
                'order_id' => 202,
                'order_amount' => '75000.00',
                'subtotal_amount' => '75000.00',
                'cogs_amount' => '40000.00',
            ],
            'status' => OutboxStatusEnum::PROCESSED,
            'journal_entry_id' => 999,
            'occurred_at' => now(),
            'processed_at' => now(),
        ]);

        // Posting engine MUST NOT be called because outbox is already PROCESSED
        $mockPostingEngine->shouldNotReceive('processOutboxEvent');

        $job = new ProcessFinancialOutboxJob($outbox->id);
        $job->handle($mockPostingEngine, app(TenantContext::class));

        $fresh = $outbox->fresh();
        $this->assertEquals(OutboxStatusEnum::PROCESSED, $fresh->status);
        $this->assertSame(999, $fresh->journal_entry_id);
    }
}
