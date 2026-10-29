<?php

namespace Tests\Unit\Finance;

use App\Models\AccountingPeriod;
use App\Models\AccountPeriodBalance;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\PostingRule;
use App\Models\PostingRuleLine;
use App\Models\PostingRuleVersion;
use App\Models\User;
use App\Modules\Finance\Contracts\PostingEngineContract;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Exceptions\ClosedAccountingPeriodException;
use App\Modules\Finance\Exceptions\UnbalancedJournalException;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Services\JournalEntryService;
use App\Modules\Finance\Services\PostingEngineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected $user;

    protected AccountingPeriod $period;

    protected ChartOfAccount $cashAccount;

    protected ChartOfAccount $salesAccount;

    protected PostingEngineService $postingEngine;

    protected JournalEntryService $journalEntryService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postingEngine = app(PostingEngineContract::class);
        $this->journalEntryService = app(JournalEntryService::class);

        // Buat open accounting period
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

        // Buat CoA dasar
        $this->cashAccount = ChartOfAccount::create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1101',
            'account_name' => 'Kas Operasional Toko',
            'account_type' => AccountTypeEnum::ASSET->value,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'CASH_ON_HAND',
            'is_active' => true,
            'is_system_locked' => false,
        ]);

        $this->salesAccount = ChartOfAccount::create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '4101',
            'account_name' => 'Pendapatan Penjualan Kasir POS',
            'account_type' => AccountTypeEnum::REVENUE->value,
            'account_subtype' => AccountSubtypeEnum::SALES_REVENUE->value,
            'normal_balance' => NormalBalanceEnum::CREDIT->value,
            'system_account_key' => 'POS_SALES_REVENUE',
            'is_active' => true,
            'is_system_locked' => false,
        ]);
    }

    public function test_can_post_balanced_manual_journal_and_updates_period_balances(): void
    {
        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-15',
            idempotencyKey: 'REF-MANUAL-001',
            lines: [
                new JournalLineDto(
                    accountId: $this->cashAccount->id,
                    debitAmount: '500000.00',
                    creditAmount: '0.00',
                    description: 'Penerimaan Kas'
                ),
                new JournalLineDto(
                    accountId: $this->salesAccount->id,
                    debitAmount: '0.00',
                    creditAmount: '500000.00',
                    description: 'Pendapatan Penjualan'
                ),
            ],
            notes: 'Test note'
        );

        $journal = $this->journalEntryService->postManualJournal($dto, $this->user);

        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals('500000.00', $journal->total_debit);
        $this->assertEquals('500000.00', $journal->total_credit);
        $this->assertCount(2, $journal->lines);

        // Periksa account_period_balances
        $cashBalance = AccountPeriodBalance::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('account_id', $this->cashAccount->id)
            ->first();

        $this->assertNotNull($cashBalance);
        $this->assertEquals('500000.00', $cashBalance->period_debit);
        $this->assertEquals('0.00', $cashBalance->period_credit);
        $this->assertEquals('500000.00', $cashBalance->closing_debit);

        $salesBalance = AccountPeriodBalance::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('account_id', $this->salesAccount->id)
            ->first();

        $this->assertNotNull($salesBalance);
        $this->assertEquals('0.00', $salesBalance->period_debit);
        $this->assertEquals('500000.00', $salesBalance->period_credit);
        $this->assertEquals('500000.00', $salesBalance->closing_credit);
    }

    public function test_rejects_unbalanced_manual_journal(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-15',
            idempotencyKey: 'REF-MANUAL-ERR',
            lines: [
                new JournalLineDto(
                    accountId: $this->cashAccount->id,
                    debitAmount: '500000.00',
                    creditAmount: '0.00',
                    description: 'Penerimaan Kas'
                ),
                new JournalLineDto(
                    accountId: $this->salesAccount->id,
                    debitAmount: '0.00',
                    creditAmount: '450000.00',
                    description: 'Pendapatan Salah'
                ),
            ],
            notes: ''
        );

        $this->journalEntryService->postManualJournal($dto, $this->user);
    }

    public function test_rejects_posting_to_closed_period(): void
    {
        // Ubah period menjadi closed
        $this->period->update(['status' => AccountingPeriodStatusEnum::CLOSED->value]);

        $this->expectException(ClosedAccountingPeriodException::class);

        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-15',
            idempotencyKey: 'REF-MANUAL-CLOSED',
            lines: [
                new JournalLineDto(
                    accountId: $this->cashAccount->id,
                    debitAmount: '100000.00',
                    creditAmount: '0.00',
                    description: 'Debit'
                ),
                new JournalLineDto(
                    accountId: $this->salesAccount->id,
                    debitAmount: '0.00',
                    creditAmount: '100000.00',
                    description: 'Kredit'
                ),
            ],
            notes: ''
        );

        $this->journalEntryService->postManualJournal($dto, $this->user);
    }

    public function test_can_reverse_posted_journal_entry(): void
    {
        $dto = new ManualJournalDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-15',
            idempotencyKey: 'REF-TO-REVERSE',
            lines: [
                new JournalLineDto(
                    accountId: $this->cashAccount->id,
                    debitAmount: '200000.00',
                    creditAmount: '0.00',
                    description: 'Debit'
                ),
                new JournalLineDto(
                    accountId: $this->salesAccount->id,
                    debitAmount: '0.00',
                    creditAmount: '200000.00',
                    description: 'Kredit'
                ),
            ],
            notes: ''
        );

        $original = $this->journalEntryService->postManualJournal($dto, $this->user);

        $reversal = $this->journalEntryService->reverseJournal(
            $original,
            Carbon::parse('2026-10-16'),
            'Koreksi pembatalan transaksi',
            $this->user
        );

        $this->assertNotNull($reversal);
        $this->assertEquals(JournalTypeEnum::REVERSAL->value, $reversal->journal_type->value);
        $this->assertEquals($original->id, $reversal->reversal_of_journal_id);
        $this->assertEquals($reversal->id, $original->fresh()->reversed_by_journal_id);

        // Reversal debit sales (200k), kredit cash (200k)
        $lines = $reversal->lines;
        $this->assertCount(2, $lines);
        $reversalDebit = $lines->firstWhere('account_id', $this->salesAccount->id);
        $reversalCredit = $lines->firstWhere('account_id', $this->cashAccount->id);

        $this->assertEquals('200000.00', $reversalDebit->debit_amount);
        $this->assertEquals('0.00', $reversalDebit->credit_amount);
        $this->assertEquals('0.00', $reversalCredit->debit_amount);
        $this->assertEquals('200000.00', $reversalCredit->credit_amount);

        // Net movement saldo harus kembali menjadi 0.00
        $cashBalance = AccountPeriodBalance::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('account_id', $this->cashAccount->id)
            ->first();
        $this->assertEquals('0.00', $cashBalance->net_movement);
    }

    public function test_posting_engine_is_idempotent_for_same_outbox_event(): void
    {
        // Siapkan Posting Rule
        $rule = PostingRule::create([
            'tenant_id' => $this->tenant->id,
            'event_code' => 'pos.order.paid',
            'rule_name' => 'POS Sales Transaction Rule',
            'source_domain' => 'POS',
            'description' => 'Test POS Rule',
            'is_active' => true,
            'approval_status' => 'APPROVED',
        ]);

        $version = PostingRuleVersion::create([
            'tenant_id' => $this->tenant->id,
            'posting_rule_id' => $rule->id,
            'version_number' => 1,
            'effective_from' => '2026-01-01',
            'status' => 'ACTIVE',
        ]);

        PostingRuleLine::create([
            'tenant_id' => $this->tenant->id,
            'posting_rule_version_id' => $version->id,
            'line_sequence' => 1,
            'side' => 'DEBIT',
            'amount_source_key' => 'total_amount',
            'account_resolution_strategy' => 'SYSTEM_KEY',
            'system_account_key' => 'CASH_ON_HAND',
            'zero_amount_behavior' => 'SKIP_LINE',
            'description_template' => 'Kas POS',
        ]);

        PostingRuleLine::create([
            'tenant_id' => $this->tenant->id,
            'posting_rule_version_id' => $version->id,
            'line_sequence' => 2,
            'side' => 'CREDIT',
            'amount_source_key' => 'total_amount',
            'account_resolution_strategy' => 'SYSTEM_KEY',
            'system_account_key' => 'POS_SALES_REVENUE',
            'zero_amount_behavior' => 'SKIP_LINE',
            'description_template' => 'Penjualan POS',
        ]);

        $outbox = FinancialEventOutbox::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'event_id' => 'EVT-TEST-001',
            'event_type' => 'pos.order.paid',
            'source_module' => 'POS',
            'source_type' => 'ORDER',
            'source_id' => 999,
            'source_number' => 'ORD-999',
            'idempotency_key' => 'EVT-TEST-IDEM-001',
            'payload' => [
                'total_amount' => 750000.00,
                'order_number' => 'ORD-999',
            ],
            'status' => 'PENDING',
            'occurred_at' => Carbon::parse('2026-10-15 10:00:00'),
        ]);

        // Eksekusi pertama
        $journal1 = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertNotNull($journal1);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal1->status->value);
        $this->assertEquals('750000.00', $journal1->total_debit);

        // Eksekusi kedua dengan outbox yang sama
        $journal2 = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertEquals($journal1->id, $journal2->id);

        // Verifikasi hanya ada 1 journal entry di DB
        $count = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'ORDER')
            ->where('source_id', 999)
            ->count();
        $this->assertEquals(1, $count);
    }
}
