<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\ExportTaskStatus;
use App\Models\AccountingPeriod;
use App\Models\ExportTask;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Jobs\GenerateFinancialStatementExportJob;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Modules\Finance\Services\FinancialStatementService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinancialStatementsReconciliationAndReproducibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected FinancialStatementService $statementService;

    protected AccountingPeriodService $periodService;

    protected JournalEntryService $journalService;

    protected ChartOfAccount $cashAccount;

    protected ChartOfAccount $arAccount;

    protected ChartOfAccount $inventoryAccount;

    protected ChartOfAccount $equipmentAccount;

    protected ChartOfAccount $apAccount;

    protected ChartOfAccount $equityAccount;

    protected ChartOfAccount $revenueAccount;

    protected ChartOfAccount $cogsAccount;

    protected ChartOfAccount $expenseAccount;

    protected ChartOfAccount $otherIncomeAccount;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->statementService = app(FinancialStatementService::class);
        $this->periodService = app(AccountingPeriodService::class);
        $this->journalService = app(JournalEntryService::class);

        $this->period = AccountingPeriod::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('m'),
            'status' => AccountingPeriodStatusEnum::OPEN,
            'is_adjustment_period' => false,
        ]);

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        // Resolusi Akun
        $findAcc = fn (string $code) => ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', $code)
            ->firstOrFail();

        $this->cashAccount = $findAcc('1101');
        $this->arAccount = $findAcc('1130');
        $this->inventoryAccount = $findAcc('1140');
        $this->equipmentAccount = $findAcc('1510');
        $this->apAccount = $findAcc('2101');
        $this->equityAccount = $findAcc('3100');
        $this->revenueAccount = $findAcc('4101');
        $this->cogsAccount = $findAcc('5101');
        $this->expenseAccount = $findAcc('6101');
        $this->otherIncomeAccount = $findAcc('7102');
    }

    public function test_trial_balance_equilibrium_and_account_hierarchy(): void
    {
        // 1. Setoran modal awal Rp 50.000.000 (Dr Kas 1101, Cr Modal 3100)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDay(),
            idempotencyKey: 'idemp_tb_equity',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '50000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->equityAccount->id, debitAmount: '0.00', creditAmount: '50000000.00'),
            ],
            notes: 'Setoran Modal Awal'
        );

        // 2. Pembelian persediaan Rp 10.000.000 kredit (Dr Persediaan 1140, Cr Utang 2101)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(2),
            idempotencyKey: 'idemp_tb_inventory',
            lines: [
                new JournalLineDto(accountId: $this->inventoryAccount->id, debitAmount: '10000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->apAccount->id, debitAmount: '0.00', creditAmount: '10000000.00'),
            ],
            notes: 'Pembelian Persediaan Kredit'
        );

        // 3. Penjualan Rp 15.000.000 tunai dengan HPP Rp 6.000.000
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(5),
            idempotencyKey: 'idemp_tb_sales',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '15000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->revenueAccount->id, debitAmount: '0.00', creditAmount: '15000000.00'),
                new JournalLineDto(accountId: $this->cogsAccount->id, debitAmount: '6000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->inventoryAccount->id, debitAmount: '0.00', creditAmount: '6000000.00'),
            ],
            notes: 'Penjualan POS Tunai & HPP'
        );

        // 4. Pembayaran beban operasional Rp 3.000.000 tunai
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(10),
            idempotencyKey: 'idemp_tb_expense',
            lines: [
                new JournalLineDto(accountId: $this->expenseAccount->id, debitAmount: '3000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '0.00', creditAmount: '3000000.00'),
            ],
            notes: 'Pembayaran Beban Operasional'
        );

        $tb = $this->statementService->generateTrialBalance($this->period->id);

        $this->assertTrue($tb['is_balanced'], 'Trial balance wajib seimbang.');
        $this->assertSame('0.00', $tb['discrepancy'], 'Selisih debit-kredit akhir harus 0.00.');
        $this->assertSame($tb['totals']['total_closing_debit'], $tb['totals']['total_closing_credit']);
        $this->assertSame($tb['totals']['total_period_debit'], $tb['totals']['total_period_credit']);
        $this->assertNotEmpty($tb['rows']);
    }

    public function test_profit_and_loss_calculation_and_gross_net_margins(): void
    {
        // 1. Pendapatan Rp 20.000.000
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(3),
            idempotencyKey: 'idemp_pnl_rev',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '20000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->revenueAccount->id, debitAmount: '0.00', creditAmount: '20000000.00'),
            ],
            notes: 'Pendapatan Usaha'
        );

        // 2. HPP Rp 8.000.000
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(3),
            idempotencyKey: 'idemp_pnl_cogs',
            lines: [
                new JournalLineDto(accountId: $this->cogsAccount->id, debitAmount: '8000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->inventoryAccount->id, debitAmount: '0.00', creditAmount: '8000000.00'),
            ],
            notes: 'Harga Pokok Penjualan'
        );

        // 3. Beban Operasional Rp 4.500.000
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(5),
            idempotencyKey: 'idemp_pnl_exp',
            lines: [
                new JournalLineDto(accountId: $this->expenseAccount->id, debitAmount: '4500000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '0.00', creditAmount: '4500000.00'),
            ],
            notes: 'Beban Operasional'
        );

        // 4. Pendapatan Lain-lain Rp 500.000
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(7),
            idempotencyKey: 'idemp_pnl_other',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '500000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->otherIncomeAccount->id, debitAmount: '0.00', creditAmount: '500000.00'),
            ],
            notes: 'Pendapatan Bunga Bank / Lain'
        );

        $pnl = $this->statementService->generateProfitAndLoss($this->period->id);

        // Total Pendapatan = 20.000.000
        $this->assertSame('20000000.00', $pnl['sections']['revenue']['total']);
        // Total HPP = 8.000.000
        $this->assertSame('8000000.00', $pnl['sections']['cogs']['total']);
        // Laba Kotor = 20.000.000 - 8.000.000 = 12.000.000
        $this->assertSame('12000000.00', $pnl['sections']['gross_profit']);
        // Beban Operasional = 4.500.000
        $this->assertSame('4500000.00', $pnl['sections']['operating_expenses']['total']);
        // Laba Operasional = 12.000.000 - 4.500.000 = 7.500.000
        $this->assertSame('7500000.00', $pnl['sections']['operating_income']);
        // Pendapatan Lain-lain = 500.000
        $this->assertSame('500000.00', $pnl['sections']['other_income']['total']);
        // Laba Bersih = 7.500.000 + 500.000 = 8.000.000
        $this->assertSame('8000000.00', $pnl['net_income']);
    }

    public function test_balance_sheet_accounting_equation_holds_with_current_year_net_income(): void
    {
        // 1. Modal Rp 50.000.000
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDay(),
            idempotencyKey: 'idemp_bs_equity',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '50000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->equityAccount->id, debitAmount: '0.00', creditAmount: '50000000.00'),
            ],
            notes: 'Setoran Modal'
        );

        // 2. Beli Peralatan Rp 15.000.000 tunai (Dr Peralatan 1510, Cr Kas 1101)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(2),
            idempotencyKey: 'idemp_bs_equipment',
            lines: [
                new JournalLineDto(accountId: $this->equipmentAccount->id, debitAmount: '15000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '0.00', creditAmount: '15000000.00'),
            ],
            notes: 'Beli Mesin Peralatan'
        );

        // 3. Penjualan Rp 10.000.000 (Dr Piutang 1130 Rp 4.000.000, Dr Kas Rp 6.000.000, Cr Pendapatan Rp 10.000.000)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(5),
            idempotencyKey: 'idemp_bs_sales',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '6000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->arAccount->id, debitAmount: '4000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->revenueAccount->id, debitAmount: '0.00', creditAmount: '10000000.00'),
            ],
            notes: 'Penjualan Campuran Kas & Piutang'
        );

        // 4. Utang Supplier Rp 5.000.000 untuk beban
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(8),
            idempotencyKey: 'idemp_bs_payable',
            lines: [
                new JournalLineDto(accountId: $this->expenseAccount->id, debitAmount: '5000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->apAccount->id, debitAmount: '0.00', creditAmount: '5000000.00'),
            ],
            notes: 'Beban Operasional Belum Dibayar'
        );

        // Net Income YTD = 10.000.000 - 5.000.000 = 5.000.000
        $bs = $this->statementService->generateBalanceSheet($this->period->id);

        $this->assertTrue($bs['is_balanced'], 'Neraca (Balance Sheet) wajib seimbang.');
        $this->assertSame('0.00', $bs['discrepancy'], 'Discrepancy Neraca harus 0.00.');
        $this->assertSame('5000000.00', $bs['equity']['current_year_net_income'], 'Laba tahun berjalan harus tepat 5.000.000.');
        $this->assertSame($bs['assets']['total_assets'], $bs['total_liabilities_and_equity'], 'Total Aset wajib identik dengan Total Liabilitas & Ekuitas.');
    }

    public function test_cash_flow_statement_reconciles_with_actual_cash_balances(): void
    {
        // 1. Setoran Modal Rp 30.000.000 (Financing Inflow)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDay(),
            idempotencyKey: 'idemp_cf_financing',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '30000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->equityAccount->id, debitAmount: '0.00', creditAmount: '30000000.00'),
            ],
            notes: 'Setoran Modal Pemilik'
        );

        // 2. Pembelian Peralatan Tunai Rp 10.000.000 (Investing Outflow)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(2),
            idempotencyKey: 'idemp_cf_investing',
            lines: [
                new JournalLineDto(accountId: $this->equipmentAccount->id, debitAmount: '10000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '0.00', creditAmount: '10000000.00'),
            ],
            notes: 'Beli Aset Tetap'
        );

        // 3. Penerimaan Penjualan Tunai Rp 8.000.000 (Operating Inflow)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(5),
            idempotencyKey: 'idemp_cf_op_inflow',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '8000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->revenueAccount->id, debitAmount: '0.00', creditAmount: '8000000.00'),
            ],
            notes: 'Kas dari Penjualan'
        );

        // 4. Pembayaran Beban Operasional Tunai Rp 3.000.000 (Operating Outflow)
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(7),
            idempotencyKey: 'idemp_cf_op_outflow',
            lines: [
                new JournalLineDto(accountId: $this->expenseAccount->id, debitAmount: '3000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '0.00', creditAmount: '3000000.00'),
            ],
            notes: 'Bayar Beban Operasional'
        );

        // Net Operating: 8.000.000 - 3.000.000 = +5.000.000
        // Net Investing: 0 - 10.000.000 = -10.000.000
        // Net Financing: 30.000.000 - 0 = +30.000.000
        // Net Cash Flow: 5.000.000 - 10.000.000 + 30.000.000 = +25.000.000
        // Saldo Akhir Kas = 25.000.000
        $cf = $this->statementService->generateCashFlowStatement($this->period->id);

        $this->assertTrue($cf['is_reconciled'], 'Laporan Arus Kas wajib terekonsiliasi.');
        $this->assertSame('0.00', $cf['difference'], 'Selisih rekonsiliasi arus kas harus 0.00.');
        $this->assertSame('0.00', $cf['beginning_cash'], 'Kas awal harus 0.00.');
        $this->assertSame('25000000.00', $cf['ending_cash'], 'Kas akhir harus 25.000.000.');
        $this->assertSame('25000000.00', $cf['net_cash_flow'], 'Arus kas bersih harus 25.000.000.');
        $this->assertSame('5000000.00', $cf['operating_activities']['net']);
        $this->assertSame('-10000000.00', $cf['investing_activities']['net']);
        $this->assertSame('30000000.00', $cf['financing_activities']['net']);
    }

    public function test_rebuild_period_balances_reproduces_account_period_balances_identically(): void
    {
        // 1. Post beberapa jurnal
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(2),
            idempotencyKey: 'idemp_rebuild_01',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '12000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->equityAccount->id, debitAmount: '0.00', creditAmount: '12000000.00'),
            ],
            notes: 'Modal Rebuild'
        );

        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDays(4),
            idempotencyKey: 'idemp_rebuild_02',
            lines: [
                new JournalLineDto(accountId: $this->expenseAccount->id, debitAmount: '2000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '0.00', creditAmount: '2000000.00'),
            ],
            notes: 'Beban Rebuild'
        );

        // 2. Tutup periode untuk membentuk materialized balances pertama kali
        $this->periodService->closePeriod($this->period, $this->user);

        $originalBalances = AccountPeriodBalance::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('store_scope_key', 0)
            ->get()
            ->keyBy('account_id');

        $this->assertNotEmpty($originalBalances);

        // 3. Hapus (wipe) saldo periode yang ada untuk menguji rekonstruksi penuh
        AccountPeriodBalance::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->delete();

        $this->assertDatabaseMissing('account_period_balances', [
            'tenant_id' => $this->tenant->id,
            'accounting_period_id' => $this->period->id,
        ]);

        // 4. Bangun ulang (rebuild) saldo periode
        $this->statementService->rebuildPeriodBalances($this->period->id, $this->tenant->id);

        // 5. Bandingkan hasil rekonstruksi dengan saldo awal
        $rebuiltBalances = AccountPeriodBalance::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('store_scope_key', 0)
            ->get()
            ->keyBy('account_id');

        $this->assertCount($originalBalances->count(), $rebuiltBalances);

        foreach ($originalBalances as $accId => $orig) {
            $this->assertArrayHasKey($accId, $rebuiltBalances);
            $rebuilt = $rebuiltBalances[$accId];

            $this->assertEquals((float) $orig->opening_debit, (float) $rebuilt->opening_debit);
            $this->assertEquals((float) $orig->opening_credit, (float) $rebuilt->opening_credit);
            $this->assertEquals((float) $orig->period_debit, (float) $rebuilt->period_debit);
            $this->assertEquals((float) $orig->period_credit, (float) $rebuilt->period_credit);
            $this->assertEquals((float) $orig->closing_debit, (float) $rebuilt->closing_debit);
            $this->assertEquals((float) $orig->closing_credit, (float) $rebuilt->closing_credit);
            $this->assertEquals((float) $orig->net_movement, (float) $rebuilt->net_movement);
        }
    }

    public function test_async_export_job_generates_excel_and_completes_export_task(): void
    {
        Storage::fake('local');

        // Post 1 transaksi untuk menghasilkan data
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date->copy()->addDay(),
            idempotencyKey: 'idemp_export_tb',
            lines: [
                new JournalLineDto(accountId: $this->cashAccount->id, debitAmount: '5000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $this->revenueAccount->id, debitAmount: '0.00', creditAmount: '5000000.00'),
            ],
            notes: 'Transaksi Ekspor'
        );

        $exportTask = ExportTask::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requested_by' => $this->user->id,
            'type' => 'FINANCIAL_STATEMENT',
            'parameters' => [
                'report_type' => 'trial_balance',
                'period_id' => $this->period->id,
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $job = new GenerateFinancialStatementExportJob($exportTask);
        $job->handle();

        $fresh = $exportTask->fresh();
        $this->assertSame(ExportTaskStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->file_path);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->error_message);

        Storage::disk('local')->assertExists($fresh->file_path);
    }
}
