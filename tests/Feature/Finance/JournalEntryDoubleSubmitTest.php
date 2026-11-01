<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Livewire\Finance\JournalEntryManager;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JournalEntryDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $financeUser;

    protected ChartOfAccount $cashAccount;

    protected ChartOfAccount $capitalAccount;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);
        app(AccountingPeriodService::class)->createFiscalYearPeriods((int) now()->format('Y'), 1, $this->tenant->id);

        $role = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->financeUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $this->cashAccount = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_type', AccountTypeEnum::ASSET)
            ->where('is_postable', true)
            ->firstOrFail();

        $this->capitalAccount = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_type', AccountTypeEnum::EQUITY)
            ->where('is_postable', true)
            ->firstOrFail();
    }

    public function test_double_submit_with_same_idempotency_key_creates_only_one_journal(): void
    {
        $this->actingAs($this->financeUser);

        $initialJournalCount = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->count();

        $testable = Livewire::test(JournalEntryManager::class)
            ->call('openWorkbenchModal');

        // Assert idempotency key was generated and is not empty
        $idempotencyKey = $testable->get('workbenchIdempotencyKey');
        $this->assertNotEmpty($idempotencyKey);
        $this->assertStringStartsWith('manual_journal:'.$this->tenant->id.':', $idempotencyKey);

        // Fill valid balanced journal lines
        $testable->set('postingDate', now()->toDateString())
            ->set('notes', 'Setoran modal anti double submit')
            ->set('lines', [
                ['account_id' => $this->cashAccount->id, 'debit_amount' => 250000.0, 'credit_amount' => 0.0, 'description' => 'Debit Kas'],
                ['account_id' => $this->capitalAccount->id, 'debit_amount' => 0.0, 'credit_amount' => 250000.0, 'description' => 'Credit Modal'],
            ]);

        // First submit: succeeds
        $testable->call('postManualJournal')
            ->assertHasNoErrors();

        // Second submit immediately after with the locked idempotency key (simulating network duplicate / rapid click)
        $testable->call('postManualJournal');

        $finalJournalCount = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('notes', 'Setoran modal anti double submit')
            ->count();

        $this->assertEquals(1, $finalJournalCount, 'Double submit must create exactly 1 journal entry.');
    }
}
