<?php

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Services\JournalEntryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualJournalAndReversalTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected ChartOfAccount $cashAccount;

    protected ChartOfAccount $equityAccount;

    protected JournalEntryService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->service = app(JournalEntryService::class);

        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-10',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'fiscal_year' => 2026,
            'period_number' => 10,
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $this->cashAccount = ChartOfAccount::create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1001',
            'account_name' => 'Kas Induk',
            'account_type' => AccountTypeEnum::ASSET->value,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'is_active' => true,
        ]);

        $this->equityAccount = ChartOfAccount::create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '3001',
            'account_name' => 'Modal Disetor',
            'account_type' => AccountTypeEnum::EQUITY->value,
            'account_subtype' => AccountSubtypeEnum::EQUITY_CAPITAL->value,
            'normal_balance' => NormalBalanceEnum::CREDIT->value,
            'is_active' => true,
        ]);
    }

    public function test_mysql_trigger_blocks_direct_modification_of_posted_journal(): void
    {
        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-10',
            idempotencyKey: 'TEST-TRIGGER-MOD-01',
            lines: [
                new JournalLineDto($this->cashAccount->id, '1000000.00', '0.00', 'Setoran Modal'),
                new JournalLineDto($this->equityAccount->id, '0.00', '1000000.00', 'Setoran Modal'),
            ]
        );

        $journal = $this->service->postManualJournal($dto, $this->user);

        // Uji trigger MySQL: Dilarang mengupdate total_debit secara langsung
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-08');

        DB::table('journal_entries')
            ->where('id', $journal->id)
            ->update(['total_debit' => 999999.00]);
    }

    public function test_mysql_trigger_blocks_deletion_of_posted_journal(): void
    {
        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-10',
            idempotencyKey: 'TEST-TRIGGER-DEL-01',
            lines: [
                new JournalLineDto($this->cashAccount->id, '500000.00', '0.00', 'Setoran'),
                new JournalLineDto($this->equityAccount->id, '0.00', '500000.00', 'Setoran'),
            ]
        );

        $journal = $this->service->postManualJournal($dto, $this->user);

        // Uji trigger MySQL: Dilarang menghapus record jurnal POSTED
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-08');

        DB::table('journal_entries')
            ->where('id', $journal->id)
            ->delete();
    }

    public function test_reversal_creates_opposite_entry_and_marks_original_as_reversed(): void
    {
        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-10',
            idempotencyKey: 'TEST-REV-01',
            lines: [
                new JournalLineDto($this->cashAccount->id, '250000.00', '0.00', 'Debit Asal'),
                new JournalLineDto($this->equityAccount->id, '0.00', '250000.00', 'Kredit Asal'),
            ]
        );

        $journal = $this->service->postManualJournal($dto, $this->user);

        $reversal = $this->service->reverseJournal(
            $journal,
            '2026-10-12',
            'Koreksi kesalahan input tanggal',
            $this->user
        );

        $this->assertEquals(JournalStatusEnum::REVERSED->value, $journal->fresh()->status->value);
        $this->assertEquals($reversal->id, $journal->fresh()->reversed_by_journal_id);
        $this->assertEquals($journal->id, $reversal->reversal_of_journal_id);
        $this->assertEquals(JournalTypeEnum::REVERSAL->value, $reversal->journal_type->value);
    }
}
