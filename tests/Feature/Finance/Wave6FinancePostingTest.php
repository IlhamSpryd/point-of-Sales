<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Exceptions\PeriodClosedException;
use App\Modules\Finance\Exceptions\UnbalancedJournalException;
use App\Modules\Finance\Services\JournalEntryService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Wave6FinancePostingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected int $periodId;

    protected int $accountDrId;

    protected int $accountCrId;

    protected JournalEntryService $journalEntryService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->journalEntryService = app(JournalEntryService::class);

        // Buat open accounting period untuk bulan Oktober 2026
        $this->periodId = DB::table('accounting_periods')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-10',
            'fiscal_year' => 2026,
            'period_number' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'status' => 'OPEN',
            'is_adjustment_period' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kas = DB::table('chart_of_accounts')
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '1101')
            ->first();

        $pendapatan = DB::table('chart_of_accounts')
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '4101')
            ->first();

        $this->accountDrId = (int) $kas->id;
        $this->accountCrId = (int) $pendapatan->id;
    }

    /**
     * T-FIN-01: Pembuatan jurnal tidak seimbang (Debit != Kredit) otomatis ditolak oleh validasi Service Layer.
     */
    public function test_t_fin_01_unbalanced_journal_creation_is_rejected_by_service(): void
    {
        $lines = [
            new JournalLineDto(
                accountId: $this->accountDrId,
                debitAmount: '500000.00',
                creditAmount: '0.00',
                description: 'Debit Kas Rp 500.000',
            ),
            new JournalLineDto(
                accountId: $this->accountCrId,
                debitAmount: '0.00',
                creditAmount: '450000.00', // Unbalanced: selisih Rp 50.000
                description: 'Kredit Pendapatan Rp 450.000',
            ),
        ];

        $this->expectException(UnbalancedJournalException::class);

        $this->journalEntryService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-15',
            idempotencyKey: 'idemp-unbalanced-test-01',
            lines: $lines,
            sourceModule: 'FINANCE',
            sourceType: 'TEST_UNBALANCED',
            journalType: JournalTypeEnum::MANUAL_ADJUSTMENT,
            notes: 'Test Unbalanced Journal'
        );
    }

    /**
     * T-FIN-02: Upaya perintah DELETE pada baris journal_entries atau journal_lines berstatus POSTED memicu penolakan SQLSTATE 45000 dari basis data.
     */
    public function test_t_fin_02_delete_on_posted_journal_entry_triggers_sqlstate_45000(): void
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JE-WAVE6-0001',
            'accounting_period_id' => $this->periodId,
            'posting_date' => '2026-10-15',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'idemp-posted-del-test-02',
            'total_debit' => 100000.00,
            'total_credit' => 100000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-08: Penghapusan jurnal POSTED/REVERSED dilarang keras.');

        DB::table('journal_entries')->where('id', $journalId)->delete();
    }

    /**
     * T-FIN-03: Percobaan posting dokumen baru ke periode akuntansi berstatus CLOSED ditolak dengan PeriodClosedException.
     */
    public function test_t_fin_03_posting_document_to_closed_period_is_rejected_with_period_closed_exception(): void
    {
        // Ubah status periode menjadi CLOSED
        DB::table('accounting_periods')
            ->where('id', $this->periodId)
            ->update([
                'status' => 'CLOSED',
                'closed_at' => now(),
            ]);

        $lines = [
            new JournalLineDto(
                accountId: $this->accountDrId,
                debitAmount: '200000.00',
                creditAmount: '0.00',
                description: 'Debit Kas Rp 200.000',
            ),
            new JournalLineDto(
                accountId: $this->accountCrId,
                debitAmount: '0.00',
                creditAmount: '200000.00',
                description: 'Kredit Pendapatan Rp 200.000',
            ),
        ];

        $this->expectException(PeriodClosedException::class);

        $this->journalEntryService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: '2026-10-15', // Tanggal berada di periode 2026-10 yang CLOSED
            idempotencyKey: 'idemp-closed-period-test-03',
            lines: $lines,
            sourceModule: 'FINANCE',
            sourceType: 'TEST_CLOSED_PERIOD',
            journalType: JournalTypeEnum::MANUAL_ADJUSTMENT,
            notes: 'Test Posting to Closed Period'
        );
    }
}
