<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSchemaAndTriggersTest extends TestCase
{
    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected int $periodId;

    protected int $accountDrId;

    protected int $accountCrId;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Buat open accounting period
        $this->periodId = DB::table('accounting_periods')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-10',
            'fiscal_year' => 2026,
            'period_number' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Buat Akun Kas & Pendapatan
        $this->accountDrId = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1101',
            'account_name' => 'Kas Operasional',
            'account_type' => 'ASSET',
            'account_subtype' => 'CASH_BANK',
            'normal_balance' => 'DEBIT',
            'system_account_key' => 'CASH_MAIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountCrId = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'account_code' => '4101',
            'account_name' => 'Pendapatan Penjualan Retail',
            'account_type' => 'REVENUE',
            'account_subtype' => 'SALES_REVENUE',
            'normal_balance' => 'CREDIT',
            'system_account_key' => 'SALES_REVENUE_RETAIL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_posted_journal_cannot_be_deleted(): void
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JE-2026-0001',
            'accounting_period_id' => $this->periodId,
            'posting_date' => '2026-10-15',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'test-idemp-001',
            'total_debit' => 100000.00,
            'total_credit' => 100000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-08: Penghapusan jurnal POSTED/REVERSED dilarang keras.');

        DB::table('journal_entries')->where('id', $journalId)->delete();
    }

    public function test_posted_journal_financial_columns_cannot_be_modified(): void
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JE-2026-0002',
            'accounting_period_id' => $this->periodId,
            'posting_date' => '2026-10-15',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'test-idemp-002',
            'total_debit' => 150000.00,
            'total_credit' => 150000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-08: Modifikasi jurnal POSTED/REVERSED dilarang.');

        DB::table('journal_entries')->where('id', $journalId)->update([
            'total_debit' => 200000.00,
        ]);
    }

    public function test_posted_journal_lines_cannot_be_updated_or_deleted(): void
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JE-2026-0003',
            'accounting_period_id' => $this->periodId,
            'posting_date' => '2026-10-15',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'test-idemp-003',
            'total_debit' => 50000.00,
            'total_credit' => 50000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineId = DB::table('journal_lines')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'journal_entry_id' => $journalId,
            'line_number' => 1,
            'account_id' => $this->accountDrId,
            'debit_amount' => 50000.00,
            'credit_amount' => 0.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Percobaan delete baris jurnal POSTED harus gagal
        try {
            DB::table('journal_lines')->where('id', $lineId)->delete();
            $this->fail('Expected QueryException was not thrown on delete');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FIN-M7-08: Baris jurnal yang sudah POSTED tidak dapat dihapus.', $e->getMessage());
        }

        // Percobaan update baris jurnal POSTED harus gagal
        try {
            DB::table('journal_lines')->where('id', $lineId)->update(['debit_amount' => 75000.00]);
            $this->fail('Expected QueryException was not thrown on update');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FIN-M7-08: Baris jurnal yang sudah POSTED tidak dapat diubah.', $e->getMessage());
        }
    }

    public function test_inserting_journal_into_closed_period_is_rejected(): void
    {
        $closedPeriodId = DB::table('accounting_periods')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-09',
            'fiscal_year' => 2026,
            'period_number' => 9,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'CLOSED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-08: Posting jurnal ke periode akuntansi CLOSED atau LOCKED ditolak.');

        DB::table('journal_entries')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JE-2026-CLOSED',
            'accounting_period_id' => $closedPeriodId,
            'posting_date' => '2026-09-15',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'test-idemp-closed',
            'total_debit' => 10000.00,
            'total_credit' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reversing_journal_state_transition_is_permitted(): void
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JE-2026-TO-REVERSE',
            'accounting_period_id' => $this->periodId,
            'posting_date' => '2026-10-15',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'test-idemp-rev-orig',
            'total_debit' => 80000.00,
            'total_credit' => 80000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reversalJournalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'journal_number' => 'REV-2026-0001',
            'accounting_period_id' => $this->periodId,
            'posting_date' => '2026-10-16',
            'journal_type' => 'REVERSAL',
            'status' => 'POSTED',
            'reversal_of_journal_id' => $journalId,
            'idempotency_key' => 'test-idemp-rev-new',
            'total_debit' => 80000.00,
            'total_credit' => 80000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Transisi POSTED -> REVERSED dengan mengisi reversed_by_journal_id diizinkan oleh trigger
        $affected = DB::table('journal_entries')->where('id', $journalId)->update([
            'status' => 'REVERSED',
            'reversed_by_journal_id' => $reversalJournalId,
            'reversed_at' => now(),
            'reversal_reason' => 'Koreksi transaksi salah imput',
        ]);

        $this->assertEquals(1, $affected);

        $fresh = DB::table('journal_entries')->where('id', $journalId)->first();
        $this->assertEquals('REVERSED', $fresh->status);
        $this->assertEquals($reversalJournalId, $fresh->reversed_by_journal_id);
    }
}
