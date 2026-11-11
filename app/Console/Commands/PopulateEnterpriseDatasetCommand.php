<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PopulateEnterpriseDatasetCommand extends Command
{
    protected $signature = 'pos:dataset:populate-enterprise
                            {--confirm-database= : Target database name as safety guard}
                            {--force : Force re-populating and refreshing enterprise tables}
                            {--dry-run : Simulate without persisting data}';

    protected $description = 'Populate all 90 empty tables across Finance, HRM, B2B Sales, Promotions and Partitioned Ledgers up to 06 Nov 2026';

    private bool $dryRun = false;
    private bool $force = false;

    public function handle(): int
    {
        $dbName = DB::getDatabaseName();
        $confirmedDb = (string) $this->option('confirm-database');

        if ($confirmedDb !== $dbName) {
            $this->error("Safety Guard: Harap sertakan --confirm-database={$dbName}");
            return self::FAILURE;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $this->force = (bool) $this->option('force');

        $this->info("=== APEIRON SYSTEMS: ENTERPRISE DATASET INJECTION (100% TABLE POPULATION) ===");
        $this->info("Target Database: {$dbName} | Mode: " . ($this->dryRun ? 'DRY-RUN' : 'LIVE EXECUTION') . ($this->force ? ' | FORCE REFRESH' : ''));

        $tenants = Tenant::whereIn('slug', ['fnb-demo', 'retail-demo'])
            ->orWhereIn('id', DB::table('users')->whereIn('email', ['ilhamsepriyadi8@gmail.com', 'ilhamsepriyadi0@gmail.com'])->pluck('tenant_id'))
            ->orWhereIn('id', [1, 2])
            ->get();
        if ($tenants->isEmpty()) {
            $this->error("Tenant F&B dan Retail tidak ditemukan di database!");
            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->info("\n--- MEMPROSES TENANT #{$tenant->id}: {$tenant->name} ---");
            if ($this->force && ! $this->dryRun) {
                $this->line("Membersihkan tabel enterprise lama untuk Tenant #{$tenant->id}...");
                $this->cleanEnterpriseTables($tenant);
            }
            $this->populateTenant($tenant);
        }

        // Global / cross-tenant tables (personal_access_tokens, partitioned ledgers)
        $this->info("\n--- MEMPROSES PARTITIONED LEDGERS & SANCTUM TOKENS ---");
        $this->seedPartitionedHistoricalLedgers();
        $this->seedPersonalAccessTokens();

        $this->info("\n=== INJEKSI DATASET ENTERPRISE SELESAI ===");
        return self::SUCCESS;
    }

    private function cleanEnterpriseTables(Tenant $tenant): void
    {
        // 1. Drop immutability triggers on journals, lines, payroll, and AP
        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_entries_before_update;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_entries_before_delete;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_lines_before_update;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_lines_before_delete;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_payroll_records_immutable_update;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_payroll_records_immutable_delete;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_ap_ledger_entries_before_update;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_ap_ledger_entries_before_delete;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_tax_ledger_entries_before_update;");
        DB::unprepared("DROP TRIGGER IF EXISTS trg_tax_ledger_entries_before_delete;");

        // 2. Re-open accounting periods so journals can be inserted/deleted
        DB::table('accounting_periods')->where('tenant_id', $tenant->id)->update(['status' => 'OPEN']);

        // 3. Delete previous enterprise data for this tenant
        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');

        DB::table('financial_event_outboxes')->where('tenant_id', $tenant->id)->delete();
        DB::table('accounting_receivable_outboxes')->where('tenant_id', $tenant->id)->delete();
        DB::table('payment_inbox')->where('tenant_id', $tenant->id)->delete();
        DB::table('whatsapp_message_outbox')->where('tenant_id', $tenant->id)->delete();

        DB::table('fixed_asset_depreciation_schedules')->where('tenant_id', $tenant->id)->delete();
        DB::table('fixed_asset_disposals')->where('tenant_id', $tenant->id)->delete();
        DB::table('fixed_assets')->where('tenant_id', $tenant->id)->delete();
        DB::table('fixed_asset_categories')->where('tenant_id', $tenant->id)->delete();

        DB::table('expense_approvals')->where('tenant_id', $tenant->id)->delete();
        DB::table('expense_attachments')->where('tenant_id', $tenant->id)->delete();
        DB::table('expense_lines')->where('tenant_id', $tenant->id)->delete();
        DB::table('expenses')->where('tenant_id', $tenant->id)->delete();
        DB::table('expense_categories')->where('tenant_id', $tenant->id)->delete();

        DB::table('bank_reconciliation_matches')->where('tenant_id', $tenant->id)->delete();
        DB::table('bank_reconciliation_sessions')->where('tenant_id', $tenant->id)->delete();
        DB::table('bank_statement_lines')->where('tenant_id', $tenant->id)->delete();
        DB::table('bank_statements')->where('tenant_id', $tenant->id)->delete();
        DB::table('cash_bank_transactions')->where('tenant_id', $tenant->id)->delete();
        DB::table('cash_bank_accounts')->where('tenant_id', $tenant->id)->delete();

        DB::table('commission_records')->where('tenant_id', $tenant->id)->delete();
        DB::table('commission_rules')->where('tenant_id', $tenant->id)->delete();

        DB::table('vouchers')->where('tenant_id', $tenant->id)->delete();
        DB::table('promotion_store_mappings')->where('tenant_id', $tenant->id)->delete();
        DB::table('promotion_rules')->where('tenant_id', $tenant->id)->delete();
        DB::table('promotion_rewards')->where('tenant_id', $tenant->id)->delete();
        DB::table('promotion_reservations')->where('tenant_id', $tenant->id)->delete();
        DB::table('promotion_campaigns')->where('tenant_id', $tenant->id)->delete();

        DB::table('customer_merge_logs')->where('tenant_id', $tenant->id)->delete();
        DB::table('kitchen_waste_logs')->where('tenant_id', $tenant->id)->delete();
        DB::table('manufacturing_orders')->where('tenant_id', $tenant->id)->delete();
        DB::table('shelf_labels')->where('tenant_id', $tenant->id)->delete();

        DB::table('tax_ledger_entries')->where('tenant_id', $tenant->id)->delete();
        DB::table('journal_lines')->where('tenant_id', $tenant->id)->delete();
        DB::table('journal_entries')->where('tenant_id', $tenant->id)->delete();
        DB::table('account_period_balances')->where('tenant_id', $tenant->id)->delete();
        DB::table('inventory_valuation_snapshots')->where('tenant_id', $tenant->id)->delete();

        DB::table('ap_ledger_entries')->where('tenant_id', $tenant->id)->delete();
        DB::table('ap_payment_allocations')->where('tenant_id', $tenant->id)->delete();
        DB::table('ap_payments')->where('tenant_id', $tenant->id)->delete();

        DB::table('ar_ledger_entries')->where('tenant_id', $tenant->id)->delete();
        DB::table('ar_payment_allocations')->where('tenant_id', $tenant->id)->delete();
        DB::table('ar_receipts')->where('tenant_id', $tenant->id)->delete();
        DB::table('sales_credit_note_applications')->where('tenant_id', $tenant->id)->delete();
        DB::table('sales_credit_notes')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_invoice_lines')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_invoices')->where('tenant_id', $tenant->id)->delete();
        DB::table('sales_order_lines')->where('tenant_id', $tenant->id)->delete();
        DB::table('sales_orders')->where('tenant_id', $tenant->id)->delete();
        DB::table('quotation_lines')->where('tenant_id', $tenant->id)->delete();
        DB::table('quotations')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_credit_logs')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_credit_accounts')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_contacts')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_addresses')->where('tenant_id', $tenant->id)->delete();
        DB::table('customer_groups')->where('tenant_id', $tenant->id)->delete();

        DB::table('store_credit_ledgers')->where('tenant_id', $tenant->id)->delete();
        DB::table('store_credit_accounts')->where('tenant_id', $tenant->id)->delete();
        DB::table('gift_card_ledgers')->where('tenant_id', $tenant->id)->delete();
        DB::table('gift_cards')->where('tenant_id', $tenant->id)->delete();

        DB::table('payroll_adjustments')->where('tenant_id', $tenant->id)->delete();
        DB::table('payroll_items')->where('tenant_id', $tenant->id)->delete();
        DB::table('payroll_records')->where('tenant_id', $tenant->id)->delete();
        DB::table('payroll_batches')->where('tenant_id', $tenant->id)->delete();
        DB::table('payroll_periods')->where('tenant_id', $tenant->id)->delete();
        DB::table('overtime_records')->where('tenant_id', $tenant->id)->delete();
        DB::table('leave_transactions')->where('tenant_id', $tenant->id)->delete();
        DB::table('leave_requests')->where('tenant_id', $tenant->id)->delete();
        DB::table('attendance_breaks')->where('tenant_id', $tenant->id)->delete();
        DB::table('attendance_records')->where('tenant_id', $tenant->id)->delete();
        DB::table('work_schedules')->where('tenant_id', $tenant->id)->delete();
        DB::table('leave_balances')->where('tenant_id', $tenant->id)->delete();
        DB::table('employee_employment_histories')->where('tenant_id', $tenant->id)->delete();
        DB::table('employee_bpjs_profiles')->where('tenant_id', $tenant->id)->delete();
        DB::table('employee_compensations')->where('tenant_id', $tenant->id)->delete();
        DB::table('employee_branch_assignments')->where('tenant_id', $tenant->id)->delete();
        DB::table('employee_bank_accounts')->where('tenant_id', $tenant->id)->delete();
        DB::table('leave_policies')->where('tenant_id', $tenant->id)->delete();
        DB::table('salary_components')->where('tenant_id', $tenant->id)->delete();
        DB::table('shift_templates')->where('tenant_id', $tenant->id)->delete();

        DB::table('period_closing_logs')->where('tenant_id', $tenant->id)->delete();
        DB::table('period_closing_checklists')->where('tenant_id', $tenant->id)->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS = 1;');

        $this->restoreTriggers();
    }

    private function restoreTriggers(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_entries_before_update;");
        DB::unprepared("
            CREATE TRIGGER trg_journal_entries_before_update BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('POSTED', 'REVERSED') THEN
                    IF OLD.status = 'POSTED' AND NEW.status = 'REVERSED' THEN
                        IF OLD.total_debit <> NEW.total_debit
                           OR OLD.total_credit <> NEW.total_credit
                           OR OLD.posting_date <> NEW.posting_date
                           OR OLD.accounting_period_id <> NEW.accounting_period_id
                           OR OLD.tenant_id <> NEW.tenant_id
                           OR OLD.journal_number <> NEW.journal_number THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi nilai finansial jurnal POSTED dilarang saat reversal.';
                        END IF;
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi jurnal POSTED/REVERSED dilarang. Gunakan jurnal pembalik (reversal).';
                    END IF;
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_entries_before_delete;");
        DB::unprepared("
            CREATE TRIGGER trg_journal_entries_before_delete BEFORE DELETE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('POSTED', 'REVERSED') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Penghapusan jurnal POSTED/REVERSED dilarang keras.';
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_lines_before_update;");
        DB::unprepared("
            CREATE TRIGGER trg_journal_lines_before_update BEFORE UPDATE ON journal_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(30);
                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                IF parent_status IN ('POSTED', 'REVERSED') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Baris jurnal yang sudah POSTED tidak dapat diubah.';
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_lines_before_delete;");
        DB::unprepared("
            CREATE TRIGGER trg_journal_lines_before_delete BEFORE DELETE ON journal_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(30);
                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                IF parent_status IN ('POSTED', 'REVERSED') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Baris jurnal yang sudah POSTED tidak dapat dihapus.';
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_payroll_records_immutable_update;");
        DB::unprepared("
            CREATE TRIGGER trg_payroll_records_immutable_update BEFORE UPDATE ON payroll_records
            FOR EACH ROW
            BEGIN
                DECLARE v_batch_status VARCHAR(20);
                SELECT status INTO v_batch_status FROM payroll_batches WHERE id = OLD.payroll_batch_id;
                IF v_batch_status IN ('FINALIZED', 'PAYMENT_PENDING', 'PAID') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL-001: Cannot update payroll record once batch is FINALIZED or PAID.';
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_payroll_records_immutable_delete;");
        DB::unprepared("
            CREATE TRIGGER trg_payroll_records_immutable_delete BEFORE DELETE ON payroll_records
            FOR EACH ROW
            BEGIN
                DECLARE v_batch_status VARCHAR(20);
                SELECT status INTO v_batch_status FROM payroll_batches WHERE id = OLD.payroll_batch_id;
                IF v_batch_status IN ('FINALIZED', 'PAYMENT_PENDING', 'PAID') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL-001: Cannot delete payroll record once batch is FINALIZED or PAID.';
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_ap_ledger_entries_before_update;");
        DB::unprepared("
            CREATE TRIGGER trg_ap_ledger_entries_before_update BEFORE UPDATE ON ap_ledger_entries
            FOR EACH ROW
            BEGIN
                IF OLD.journal_entry_id IS NOT NULL AND OLD.journal_entry_id <> NEW.journal_entry_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi journal_entry_id pada ap_ledger_entries yang sudah terpasang dilarang.';
                END IF;
                IF OLD.debit_amount <> NEW.debit_amount
                   OR OLD.credit_amount <> NEW.credit_amount
                   OR OLD.running_balance <> NEW.running_balance
                   OR OLD.entry_type <> NEW.entry_type
                   OR OLD.effective_date <> NEW.effective_date
                   OR OLD.supplier_id <> NEW.supplier_id
                   OR OLD.tenant_id <> NEW.tenant_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Buku pembantu AP (ap_ledger_entries) bersifat imutabel (append-only).';
                END IF;
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_ap_ledger_entries_before_delete;");
        DB::unprepared("
            CREATE TRIGGER trg_ap_ledger_entries_before_delete BEFORE DELETE ON ap_ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Penghapusan baris pada ap_ledger_entries dilarang keras.';
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_tax_ledger_entries_before_update;");
        DB::unprepared("
            CREATE TRIGGER trg_tax_ledger_entries_before_update BEFORE UPDATE ON tax_ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-09: Buku pembantu pajak (tax_ledger_entries) bersifat imutabel dan tidak dapat diubah.';
            END
        ");

        DB::unprepared("DROP TRIGGER IF EXISTS trg_tax_ledger_entries_before_delete;");
        DB::unprepared("
            CREATE TRIGGER trg_tax_ledger_entries_before_delete BEFORE DELETE ON tax_ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-09: Buku pembantu pajak (tax_ledger_entries) bersifat imutabel dan tidak dapat dihapus.';
            END
        ");
    }

    private function populateTenant(Tenant $tenant): void
    {
        $stores = DB::table('stores')->where('tenant_id', $tenant->id)->get();
        $users = DB::table('users')->where('tenant_id', $tenant->id)->get();
        $employees = DB::table('employees')->where('tenant_id', $tenant->id)->get();
        if ($employees->isEmpty()) {
            $this->callSilently('hr:backfill-employees-from-users', ['--tenant' => $tenant->id]);
            $employees = DB::table('employees')->where('tenant_id', $tenant->id)->get();
        }
        $userId = $users->first()?->id ?? ($tenant->id === 2 ? 4 : 47);

        $this->line("1. Chart of Accounts, Currencies & Tax Engine...");
        $coaMap = $this->seedChartOfAccountsAndTax($tenant);

        $this->line("2. Accounting Periods (Initialized OPEN)...");
        $periods = $this->seedAccountingPeriods($tenant, $userId);

        $this->line("3. Cash & Bank Accounts, Statements, Transactions & Reconciliations...");
        $cashBankMap = $this->seedCashAndBank($tenant, $stores, $coaMap, $userId);

        $this->line("4. Fixed Assets, Categories, Depreciation & Disposal...");
        $this->seedFixedAssets($tenant, $stores, $coaMap, $periods, $userId);

        $this->line("5. Operational Expenses, Lines, Approvals & Attachments...");
        $this->seedExpenses($tenant, $stores, $coaMap, $cashBankMap, $userId);

        $this->line("6. General Ledger Journals (Double-Entry Balanced) & Period Balances...");
        $this->seedGeneralLedgerAndTaxEntries($tenant, $stores, $coaMap, $periods, $userId);

        $this->line("7. AP Subledger (Ledger Entries, Payments & Allocations)...");
        $this->seedApSubledger($tenant, $stores, $cashBankMap, $userId);

        $this->line("8. B2B Sales & CRM (Groups, Addresses, Contacts, Credit, Quotations, Orders, Invoices, Credit Notes)...");
        $this->seedB2bSalesAndCrm($tenant, $stores, $userId);

        $this->line("9. Customer Loyalty Instruments (Store Credit & Gift Cards)...");
        $this->seedCustomerCreditAndInstruments($tenant, $stores, $userId);

        $this->line("10. 9 New Enterprise Tables (Departments, Job Titles, Targets, Lots, Recipes, Tax Submissions, Webhooks)...");
        $this->seedNineEnterpriseTables($tenant, $stores, $users, $employees, $userId);

        $this->line("11. HRM & Payroll (Compensations, Schedules, Attendance, Leaves, Overtime, Payroll, Commissions)...");
        $this->seedHrmAndPayroll($tenant, $stores, $employees, $userId);

        $this->line("12. Promotions, Operations & Outboxes (Campaigns, Shelf Labels, Waste, Approvals, WhatsApp)...");
        $this->seedPromotionsAndOperations($tenant, $stores, $userId);

        $this->line("13. Closing Past Accounting Periods (2026-08 s/d 2026-10 CLOSED, 2026-11 OPEN)...");
        $this->closePastAccountingPeriods($tenant, $periods, $userId);
    }

    private function seedChartOfAccountsAndTax(Tenant $tenant): array
    {
        // 1. Chart of Accounts
        $existingCoa = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->pluck('id', 'account_code')->toArray();
        if (empty($existingCoa)) {
            (new \Database\Seeders\ChartOfAccountsSeeder())->seedForTenant($tenant->id);
            $existingCoa = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->pluck('id', 'account_code')->toArray();
        }

        // 2. Currencies
        $currencies = [
            ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimal_places' => 0, 'is_base' => 1],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => 0],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2, 'is_base' => 0],
        ];
        foreach ($currencies as $curr) {
            DB::table('currencies')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'code' => $curr['code']],
                array_merge($curr, ['tenant_id' => $tenant->id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 3. Exchange Rates
        $rates = [
            ['from_currency' => 'USD', 'to_currency' => 'IDR', 'rate' => 15850.000000, 'inverse_rate' => 0.00006309, 'effective_date' => '2025-11-01', 'rate_source' => 'Bank Indonesia JISDOR'],
            ['from_currency' => 'USD', 'to_currency' => 'IDR', 'rate' => 16120.000000, 'inverse_rate' => 0.00006203, 'effective_date' => '2026-05-01', 'rate_source' => 'Bank Indonesia JISDOR'],
            ['from_currency' => 'USD', 'to_currency' => 'IDR', 'rate' => 15650.000000, 'inverse_rate' => 0.00006390, 'effective_date' => '2026-11-01', 'rate_source' => 'Bank Indonesia JISDOR'],
            ['from_currency' => 'SGD', 'to_currency' => 'IDR', 'rate' => 11920.000000, 'inverse_rate' => 0.00008389, 'effective_date' => '2025-11-01', 'rate_source' => 'Bank Indonesia JISDOR'],
            ['from_currency' => 'SGD', 'to_currency' => 'IDR', 'rate' => 12150.000000, 'inverse_rate' => 0.00008230, 'effective_date' => '2026-05-01', 'rate_source' => 'Bank Indonesia JISDOR'],
            ['from_currency' => 'SGD', 'to_currency' => 'IDR', 'rate' => 11880.000000, 'inverse_rate' => 0.00008418, 'effective_date' => '2026-11-01', 'rate_source' => 'Bank Indonesia JISDOR'],
        ];
        foreach ($rates as $r) {
            DB::table('exchange_rates')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'from_currency' => $r['from_currency'], 'effective_date' => $r['effective_date']],
                array_merge($r, ['tenant_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 4. Tax Codes & Rates & Mappings (Compliant with chk_tc_tax_type & chk_tc_calc_type)
        $taxDefinitions = [
            ['code' => 'PPN11', 'name' => 'Pajak Pertambahan Nilai 11%', 'tax_type' => 'VAT_PPN', 'rate' => 11.0000, 'calc' => 'EXCLUSIVE'],
            ['code' => 'PPN12', 'name' => 'Pajak Pertambahan Nilai 12% 2025', 'tax_type' => 'VAT_PPN', 'rate' => 12.0000, 'calc' => 'EXCLUSIVE'],
            ['code' => 'PB1', 'name' => 'Pajak Restoran Daerah 10%', 'tax_type' => 'LOCAL_PB1', 'rate' => 10.0000, 'calc' => 'EXCLUSIVE'],
            ['code' => 'PPH21', 'name' => 'PPh Pasal 21 Karyawan TER', 'tax_type' => 'WITHHOLDING_PPH', 'rate' => 5.0000, 'calc' => 'INCLUSIVE'],
            ['code' => 'PPH23', 'name' => 'PPh Pasal 23 Jasa 2%', 'tax_type' => 'WITHHOLDING_PPH', 'rate' => 2.0000, 'calc' => 'EXCLUSIVE'],
        ];

        $salesTaxAccId = $existingCoa['2140'] ?? $existingCoa['2101'] ?? array_values($existingCoa)[0];
        $purchaseTaxAccId = $existingCoa['1160'] ?? $existingCoa['1104'] ?? array_values($existingCoa)[0];

        foreach ($taxDefinitions as $tDef) {
            DB::table('tax_codes')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'code' => $tDef['code']],
                [
                    'tenant_id' => $tenant->id,
                    'code' => $tDef['code'],
                    'name' => $tDef['name'],
                    'tax_type' => $tDef['tax_type'],
                    'calculation_type' => $tDef['calc'],
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $tcRow = DB::table('tax_codes')->where('tenant_id', $tenant->id)->where('code', $tDef['code'])->first();
            if ($tcRow) {
                DB::table('tax_rates')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'tax_code_id' => $tcRow->id, 'effective_from' => '2025-01-01'],
                    [
                        'tenant_id' => $tenant->id,
                        'tax_code_id' => $tcRow->id,
                        'rate_percent' => $tDef['rate'],
                        'effective_from' => '2025-01-01',
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                DB::table('tax_account_mappings')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'tax_code_id' => $tcRow->id],
                    [
                        'tenant_id' => $tenant->id,
                        'tax_code_id' => $tcRow->id,
                        'sales_tax_account_id' => $salesTaxAccId,
                        'purchase_tax_account_id' => $purchaseTaxAccId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 5. Posting Rules
        $ruleRow = DB::table('posting_rules')->where('tenant_id', $tenant->id)->where('event_code', 'POS_SALE_COMPLETED')->first();
        if (!$ruleRow) {
            $ruleId = DB::table('posting_rules')->insertGetId([
                'tenant_id' => $tenant->id,
                'event_code' => 'POS_SALE_COMPLETED',
                'rule_name' => 'POS Cash & QRIS Sales Posting Rule',
                'source_domain' => 'SALES',
                'description' => 'Automated GL journal for completed POS transactions',
                'is_active' => 1,
                'approval_status' => 'APPROVED',
                'approved_at' => '2025-10-25 09:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $versionId = DB::table('posting_rule_versions')->insertGetId([
                'tenant_id' => $tenant->id,
                'posting_rule_id' => $ruleId,
                'version_number' => 1,
                'effective_from' => '2025-10-01',
                'status' => 'ACTIVE',
                'change_summary' => 'Initial canonical rule',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $isFnb = ($tenant->id === 1 || str_contains($tenant->slug, 'fnb') || str_contains($tenant->name, 'Coffee'));
            $salesAccCode = $isFnb ? '4102' : '4101';
            $cashAccId = $existingCoa['1101'] ?? array_values($existingCoa)[0];
            $salesAccId = $existingCoa[$salesAccCode] ?? array_values($existingCoa)[0];

            DB::table('posting_rule_lines')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'posting_rule_version_id' => $versionId,
                    'line_sequence' => 1,
                    'side' => 'DEBIT',
                    'amount_source_key' => 'payment.amount',
                    'account_resolution_strategy' => 'EXPLICIT',
                    'account_id' => $cashAccId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'posting_rule_version_id' => $versionId,
                    'line_sequence' => 2,
                    'side' => 'CREDIT',
                    'amount_source_key' => 'order.subtotal',
                    'account_resolution_strategy' => 'EXPLICIT',
                    'account_id' => $salesAccId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        return $existingCoa;
    }

    private function seedAccountingPeriods(Tenant $tenant, int $userId): array
    {
        $periods = [];
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-11-30');

        $curr = $start->copy();
        while ($curr->lte($end)) {
            $periodCode = $curr->format('Y-m');
            $fiscalYear = (int) $curr->format('Y');
            $periodNumber = (int) $curr->format('m');
            $startDate = $curr->copy()->startOfMonth()->toDateString();
            $endDate = $curr->copy()->endOfMonth()->toDateString();
            $isClosed = $curr->format('Y-m') < '2026-11';

            $existing = DB::table('accounting_periods')->where('tenant_id', $tenant->id)->where('period_code', $periodCode)->first();
            if ($existing) {
                $periodId = $existing->id;
                DB::table('accounting_periods')->where('id', $periodId)->update(['status' => 'OPEN']);
            } else {
                $periodId = DB::table('accounting_periods')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'period_code' => $periodCode,
                    'fiscal_year' => $fiscalYear,
                    'period_number' => $periodNumber,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'OPEN',
                    'is_adjustment_period' => 0,
                    'closed_at' => null,
                    'closed_by_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $periods[$periodCode] = (object) [
                'id' => $periodId,
                'code' => $periodCode,
                'year' => $fiscalYear,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_closed' => $isClosed,
            ];

            $curr->addMonth();
        }

        return $periods;
    }

    private function closePastAccountingPeriods(Tenant $tenant, array $periods, int $userId): void
    {
        foreach ($periods as $pCode => $p) {
            if ($p->is_closed) {
                $mEnd = Carbon::parse($p->end_date);
                DB::table('accounting_periods')->where('id', $p->id)->update([
                    'status' => 'CLOSED',
                    'closed_at' => $mEnd->copy()->addHours(20)->toDateTimeString(),
                    'closed_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);

                $chkCount = DB::table('period_closing_checklists')->where('tenant_id', $tenant->id)->where('accounting_period_id', $p->id)->count();
                if ($chkCount === 0) {
                    $checklistItems = [
                        'RECONCILE_BANK' => 'Semua rekening koran BCA & Mandiri telah direkonsiliasi 100%',
                        'INVENTORY_VALUATION' => 'Stock opname & valuasi HPP telah diverifikasi',
                        'DEPRECIATION_POSTED' => 'Penyusutan aset tetap bulanan telah diposting ke GL',
                        'SUBLEDGER_BALANCED' => 'Buku pembantu AP & AR seimbang dengan buku besar',
                        'TAX_SUBMITTED' => 'Laporan SPT Masa PPN & PPh 21 telah diverifikasi',
                    ];
                    foreach ($checklistItems as $chkCode => $chkDesc) {
                        DB::table('period_closing_checklists')->insert([
                            'tenant_id' => $tenant->id,
                            'accounting_period_id' => $p->id,
                            'gate_code' => $chkCode,
                            'gate_name' => $chkDesc,
                            'status' => 'PASSED',
                            'discrepancy_amount' => 0.00,
                            'diagnostics_json' => json_encode(['verified' => true]),
                            'checked_by_user_id' => $userId,
                            'checked_at' => $mEnd->copy()->addHours(18)->toDateTimeString(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('period_closing_logs')->insert([
                        'tenant_id' => $tenant->id,
                        'accounting_period_id' => $p->id,
                        'action' => 'CLOSE_PERIOD',
                        'user_id' => $userId,
                        'reason' => "Tutup buku periode {$pCode} berhasil dilakukan.",
                        'previous_status' => 'OPEN',
                        'new_status' => 'CLOSED',
                        'created_at' => $mEnd->copy()->addHours(20)->toDateTimeString(),
                    ]);
                }
            }
        }
    }

    private function seedCashAndBank(Tenant $tenant, $stores, array $coaMap, int $userId): array
    {
        $bankAccId = $coaMap['1110'] ?? array_values($coaMap)[0];
        $pgAccId = $coaMap['1120'] ?? array_values($coaMap)[0];
        $cashAccId = $coaMap['1101'] ?? array_values($coaMap)[0];
        $mainStore = $stores->first();

        // 1. Cash & Bank Accounts (Compliant with chk_cba_account_type: BANK_ACCOUNT, PETTY_CASH, PAYMENT_GATEWAY_CLEARING)
        $bcaRow = DB::table('cash_bank_accounts')->where('tenant_id', $tenant->id)->where('account_code', 'BCA-OPS-' . $tenant->id)->first();
        if ($bcaRow) {
            $bcaId = $bcaRow->id;
        } else {
            $bcaId = DB::table('cash_bank_accounts')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore?->id,
                'account_code' => 'BCA-OPS-' . $tenant->id,
                'account_name' => 'Bank BCA Rekening Operasional',
                'account_type' => 'BANK_ACCOUNT',
                'gl_account_id' => $bankAccId,
                'bank_name' => 'Bank Central Asia',
                'bank_account_number' => '80019283' . $tenant->id . '1',
                'bank_account_holder' => $tenant->name,
                'currency_code' => 'IDR',
                'opening_balance' => 250000000.00,
                'current_balance' => 385420000.00,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $mandiriRow = DB::table('cash_bank_accounts')->where('tenant_id', $tenant->id)->where('account_code', 'PG-QRIS-' . $tenant->id)->first();
        if ($mandiriRow) {
            $mandiriId = $mandiriRow->id;
        } else {
            $mandiriId = DB::table('cash_bank_accounts')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore?->id,
                'account_code' => 'PG-QRIS-' . $tenant->id,
                'account_name' => 'Kliring Payment Gateway & QRIS',
                'account_type' => 'PAYMENT_GATEWAY_CLEARING',
                'gl_account_id' => $pgAccId,
                'bank_name' => 'Midtrans / QRIS Kliring',
                'bank_account_number' => 'PG-MIDTRANS-' . $tenant->id,
                'bank_account_holder' => $tenant->name,
                'currency_code' => 'IDR',
                'opening_balance' => 50000000.00,
                'current_balance' => 85200000.00,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pettyRow = DB::table('cash_bank_accounts')->where('tenant_id', $tenant->id)->where('account_code', 'PETTY-' . $tenant->id)->first();
        if ($pettyRow) {
            $pettyCashId = $pettyRow->id;
        } else {
            $pettyCashId = DB::table('cash_bank_accounts')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore?->id,
                'account_code' => 'PETTY-' . $tenant->id,
                'account_name' => 'Kas Kecil Operasional Outlet',
                'account_type' => 'PETTY_CASH',
                'gl_account_id' => $cashAccId,
                'currency_code' => 'IDR',
                'opening_balance' => 5000000.00,
                'current_balance' => 4820000.00,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Cash Bank Transactions & Bank Statements for BCA
        $txCount = DB::table('cash_bank_transactions')->where('tenant_id', $tenant->id)->count();
        if ($txCount === 0) {
            $dates = ['2026-08-18', '2026-08-28', '2026-09-08', '2026-09-18', '2026-09-28', '2026-10-08', '2026-10-18', '2026-10-28', '2026-11-05'];
            $runningBal = 250000000.00;

            foreach ($dates as $idx => $dt) {
                $amountIn = 45000000.00 + ($idx * 1500000);
                $runningBal += $amountIn;

                $txId = DB::table('cash_bank_transactions')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'store_id' => $mainStore?->id,
                    'cash_bank_account_id' => $bcaId,
                    'transaction_number' => 'CBT-' . str_replace('-', '', $dt) . '-' . Str::upper(Str::random(4)),
                    'transaction_type' => 'PG_SETTLEMENT',
                    'transaction_date' => $dt,
                    'amount' => $amountIn,
                    'running_balance_after' => $runningBal,
                    'status' => 'POSTED',
                    'reference_number' => 'SETTLE-POS-' . $dt,
                    'description' => 'Settlement Merchant QRIS & EDC POS',
                    'idempotency_key' => 'cbt-dep-' . $tenant->id . '-' . $dt,
                    'created_by_user_id' => $userId,
                    'created_at' => $dt . ' 22:00:00',
                    'updated_at' => $dt . ' 22:00:00',
                ]);

                // Bank statement per month
                if ($idx < 12) {
                    $mDate = Carbon::parse($dt);
                    $stmtNum = 'STMT-BCA-' . $tenant->id . '-' . $mDate->format('Ym');
                    $existingStmt = DB::table('bank_statements')->where('tenant_id', $tenant->id)->where('statement_number', $stmtNum)->first();
                    if ($existingStmt) {
                        $stmtId = $existingStmt->id;
                        DB::table('bank_statements')->where('id', $stmtId)->update([
                            'closing_balance' => $runningBal,
                            'updated_at' => now(),
                        ]);
                    } else {
                        $stmtId = DB::table('bank_statements')->insertGetId([
                            'tenant_id' => $tenant->id,
                            'cash_bank_account_id' => $bcaId,
                            'statement_number' => $stmtNum,
                            'statement_date' => $mDate->endOfMonth()->toDateString(),
                            'opening_balance' => $runningBal - $amountIn,
                            'closing_balance' => $runningBal,
                            'imported_file_path' => 'statements/bca_' . $mDate->format('Ym') . '.csv',
                            'imported_by_user_id' => $userId,
                            'status' => 'RECONCILED',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $maxLine = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $stmtId)->max('line_number');
                    $stmtLineId = DB::table('bank_statement_lines')->insertGetId([
                        'tenant_id' => $tenant->id,
                        'bank_statement_id' => $stmtId,
                        'cash_bank_account_id' => $bcaId,
                        'line_number' => $maxLine + 1,
                        'value_date' => $dt,
                        'external_reference' => 'BCA-REF-' . $mDate->format('Ymd') . '-' . ($maxLine + 1),
                        'debit_amount' => 0.00,
                        'credit_amount' => $amountIn,
                        'running_balance' => $runningBal,
                        'description' => 'CR SETTLEMENT QRIS APEIRON',
                        'line_fingerprint_hash' => hash('sha256', $tenant->id . '-' . $stmtId . '-' . $dt . '-' . $amountIn . '-' . ($maxLine + 1)),
                        'match_status' => 'MATCHED',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Reconciliation session & match
                    $sessionNum = 'RECON-' . $tenant->id . '-' . $mDate->format('Y-m');
                    $existingSession = DB::table('bank_reconciliation_sessions')->where('tenant_id', $tenant->id)->where('session_number', $sessionNum)->first();
                    if ($existingSession) {
                        $sessionId = $existingSession->id;
                        DB::table('bank_reconciliation_sessions')->where('id', $sessionId)->update([
                            'statement_ending_balance' => $runningBal,
                            'book_ending_balance' => $runningBal,
                            'updated_at' => now(),
                        ]);
                    } else {
                        $sessionId = DB::table('bank_reconciliation_sessions')->insertGetId([
                            'tenant_id' => $tenant->id,
                            'cash_bank_account_id' => $bcaId,
                            'session_number' => $sessionNum,
                            'statement_end_date' => $mDate->endOfMonth()->toDateString(),
                            'statement_ending_balance' => $runningBal,
                            'book_ending_balance' => $runningBal,
                            'unreconciled_difference' => 0.00,
                            'status' => 'COMPLETED',
                            'started_by_user_id' => $userId,
                            'completed_by_user_id' => $userId,
                            'completed_at' => $mDate->endOfMonth()->addHours(15)->toDateTimeString(),
                            'notes' => 'Rekonsiliasi otomatis 100% kliring settlement POS',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('bank_reconciliation_matches')->insert([
                        'tenant_id' => $tenant->id,
                        'reconciliation_session_id' => $sessionId,
                        'bank_statement_line_id' => $stmtLineId,
                        'cash_bank_transaction_id' => $txId,
                        'match_type' => 'EXACT_AUTO',
                        'matched_amount' => $amountIn,
                        'matched_by_user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return ['bca' => $bcaId, 'mandiri' => $mandiriId, 'petty' => $pettyCashId];
    }

    private function seedFixedAssets(Tenant $tenant, $stores, array $coaMap, array $periods, int $userId): void
    {
        $assetAccId = $coaMap['1210'] ?? array_values($coaMap)[0];
        $deprAccId = $coaMap['1219'] ?? array_values($coaMap)[0];
        $expAccId = $coaMap['6400'] ?? array_values($coaMap)[0];

        $cat1 = DB::table('fixed_asset_categories')
            ->where('tenant_id', $tenant->id)
            ->where('category_code', 'EQ-KITCHEN-' . $tenant->id)
            ->value('id');

        if (!$cat1) {
            $cat1 = DB::table('fixed_asset_categories')->insertGetId([
                'tenant_id' => $tenant->id,
                'category_code' => 'EQ-KITCHEN-' . $tenant->id,
                'category_name' => 'Peralatan Espresso & Mesin Dapur Komersial',
                'asset_gl_account_id' => $assetAccId,
                'accum_depr_gl_account_id' => $deprAccId,
                'depr_expense_gl_account_id' => $expAccId,
                'useful_life_months' => 60,
                'depreciation_method' => 'STRAIGHT_LINE',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cat2 = DB::table('fixed_asset_categories')
            ->where('tenant_id', $tenant->id)
            ->where('category_code', 'IT-POS-' . $tenant->id)
            ->value('id');

        if (!$cat2) {
            $cat2 = DB::table('fixed_asset_categories')->insertGetId([
                'tenant_id' => $tenant->id,
                'category_code' => 'IT-POS-' . $tenant->id,
                'category_name' => 'Perangkat POS Terminal & Hardware IT',
                'asset_gl_account_id' => $assetAccId,
                'accum_depr_gl_account_id' => $deprAccId,
                'depr_expense_gl_account_id' => $expAccId,
                'useful_life_months' => 48,
                'depreciation_method' => 'STRAIGHT_LINE',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('fixed_assets')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $mainStore = $stores->first();
        $assetNames = $tenant->id === 2 ? [
            ['name' => 'La Marzocco Linea PB 2-Group Espresso Machine', 'cost' => 185000000.00, 'cat' => $cat1],
            ['name' => 'Mahlkönig EK43 Commercial Coffee Grinder', 'cost' => 48000000.00, 'cat' => $cat1],
            ['name' => 'Apeiron POS Dual-Screen Touch Terminal Flagship', 'cost' => 18000000.00, 'cat' => $cat2],
        ] : [
            ['name' => 'Sinmag 3-Deck Industrial Electric Bakery Oven', 'cost' => 145000000.00, 'cat' => $cat1],
            ['name' => 'Spiral Dough Mixer 50Kg Heavy Duty', 'cost' => 62000000.00, 'cat' => $cat1],
            ['name' => 'Sagara POS Station Central Bakery', 'cost' => 15000000.00, 'cat' => $cat2],
        ];

        foreach ($assetNames as $a) {
            $monthlyDepr = round($a['cost'] / 60, 2);
            $assetId = DB::table('fixed_assets')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore?->id,
                'category_id' => $a['cat'],
                'asset_code' => 'AST-' . $tenant->id . '-' . Str::upper(Str::random(5)),
                'asset_name' => $a['name'],
                'acquisition_date' => '2026-08-01',
                'acquisition_cost' => $a['cost'],
                'salvage_value' => 5000000.00,
                'useful_life_months' => 60,
                'depreciation_method' => 'STRAIGHT_LINE',
                'status' => 'ACTIVE',
                'accumulated_depreciation' => $monthlyDepr * 3,
                'net_book_value' => $a['cost'] - ($monthlyDepr * 3),
                'location_note' => 'Outlet Utama Flagship',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Schedules across 13 months
            $currBal = $a['cost'];
            $accum = 0;
            foreach ($periods as $pCode => $p) {
                $accum += $monthlyDepr;
                $currBal -= $monthlyDepr;
                DB::table('fixed_asset_depreciation_schedules')->insert([
                    'tenant_id' => $tenant->id,
                    'fixed_asset_id' => $assetId,
                    'accounting_period_id' => $p->id,
                    'period_year' => $p->year,
                    'period_month' => (int) substr($pCode, 5, 2),
                    'depreciation_amount' => $monthlyDepr,
                    'accumulated_depreciation_after' => $accum,
                    'net_book_value_after' => $currBal,
                    'status' => $p->is_closed ? 'POSTED' : 'PENDING',
                    'posted_at' => $p->is_closed ? $p->end_date . ' 23:00:00' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Disposal for 1 old asset
        $disposedAssetId = DB::table('fixed_assets')->insertGetId([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore?->id,
            'category_id' => $cat1,
            'asset_code' => 'AST-OLD-' . $tenant->id . '-DISP',
            'asset_name' => 'Mesin Blender Lama / Grinder Portable Ex-Demo',
            'acquisition_date' => '2024-01-10',
            'acquisition_cost' => 12000000.00,
            'salvage_value' => 0.00,
            'useful_life_months' => 24,
            'depreciation_method' => 'STRAIGHT_LINE',
            'status' => 'DISPOSED',
            'accumulated_depreciation' => 12000000.00,
            'net_book_value' => 0.00,
            'location_note' => 'Gudang Logistik',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fixed_asset_disposals')->insert([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore?->id,
            'fixed_asset_id' => $disposedAssetId,
            'disposal_date' => '2026-08-20',
            'disposal_type' => 'SCRAP',
            'proceeds_amount' => 1500000.00,
            'book_value_at_disposal' => 0.00,
            'gain_loss_amount' => 1500000.00,
            'reason' => 'Unit rusak total, dilelang sebagai besi tua & suku cadang',
            'approved_by_user_id' => $userId,
            'created_at' => '2026-08-20 14:00:00',
            'updated_at' => '2026-08-20 14:00:00',
        ]);
    }

    private function seedExpenses(Tenant $tenant, $stores, array $coaMap, array $cashBankMap, int $userId): void
    {
        $rentAccId = $coaMap['6200'] ?? array_values($coaMap)[0];
        $utilAccId = $coaMap['6210'] ?? array_values($coaMap)[0];
        $maintAccId = $coaMap['6500'] ?? array_values($coaMap)[0];

        $categories = [
            ['code' => 'RENT-' . $tenant->id, 'name' => 'Sewa Bangunan & Outlet', 'acc' => $rentAccId],
            ['code' => 'UTILITY-' . $tenant->id, 'name' => 'Listrik PLN, Air PAM & Gas', 'acc' => $utilAccId],
            ['code' => 'MAINT-' . $tenant->id, 'name' => 'Perawatan & Servis Mesin Berkala', 'acc' => $maintAccId],
        ];

        $catIds = [];
        foreach ($categories as $cat) {
            $catIds[$cat['code']] = DB::table('expense_categories')
                ->where('tenant_id', $tenant->id)
                ->where('category_code', $cat['code'])
                ->value('id');

            if (!$catIds[$cat['code']]) {
                $catIds[$cat['code']] = DB::table('expense_categories')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'category_code' => $cat['code'],
                    'category_name' => $cat['name'],
                    'expense_gl_account_id' => $cat['acc'],
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (DB::table('expenses')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $dates = ['2026-08-20', '2026-09-05', '2026-09-20', '2026-10-05', '2026-10-20', '2026-11-02'];
        foreach ($dates as $idx => $dt) {
            $amount = 8500000.00 + ($idx * 400000);
            $store = $stores[$idx % count($stores)];

            $expId = DB::table('expenses')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'category_id' => $catIds['UTILITY-' . $tenant->id],
                'expense_number' => 'EXP-' . str_replace('-', '', $dt) . '-' . Str::upper(Str::random(4)),
                'expense_date' => $dt,
                'subtotal_amount' => $amount,
                'tax_amount' => 0.00,
                'total_amount' => $amount,
                'description' => "Pembayaran Tagihan Listrik PLN & Air PAM Cabang {$store->name}",
                'status' => 'APPROVED',
                'submitted_by_user_id' => $userId,
                'submitted_at' => $dt . ' 09:00:00',
                'approved_by_user_id' => $userId,
                'approved_at' => $dt . ' 09:45:00',
                'cash_bank_account_id' => $cashBankMap['bca'],
                'paid_at' => $dt . ' 10:00:00',
                'created_at' => $dt . ' 09:00:00',
                'updated_at' => $dt . ' 10:00:00',
            ]);

            DB::table('expense_lines')->insert([
                'tenant_id' => $tenant->id,
                'expense_id' => $expId,
                'line_number' => 1,
                'description' => "Listrik PLN & Air {$store->name}",
                'amount' => $amount,
                'tax_code_id' => null,
                'tax_amount' => 0.00,
                'created_at' => $dt . ' 09:00:00',
                'updated_at' => $dt . ' 09:00:00',
            ]);

            DB::table('expense_approvals')->insert([
                'tenant_id' => $tenant->id,
                'expense_id' => $expId,
                'approver_user_id' => $userId,
                'action' => 'APPROVED',
                'notes' => 'Tagihan utilitas bulanan valid sesuai meteran PLN.',
                'created_at' => $dt . ' 09:45:00',
                'updated_at' => $dt . ' 09:45:00',
            ]);

            DB::table('expense_attachments')->insert([
                'tenant_id' => $tenant->id,
                'expense_id' => $expId,
                'file_name' => "tagihan_pln_{$dt}.pdf",
                'file_path' => "attachments/expenses/{$tenant->id}/pln_{$dt}.pdf",
                'file_size' => 142850,
                'mime_type' => 'application/pdf',
                'created_at' => $dt . ' 09:00:00',
                'updated_at' => $dt . ' 09:00:00',
            ]);
        }
    }

    private function seedGeneralLedgerAndTaxEntries(Tenant $tenant, $stores, array $coaMap, array $periods, int $userId): void
    {
        if (DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', 'POS_SALES')->count() > 0) {
            return;
        }

        $isFnb = ($tenant->id === 1 || str_contains($tenant->slug, 'fnb') || str_contains($tenant->name, 'Coffee'));
        $salesAccCode = $isFnb ? '4102' : '4101';
        $cogsAccCode = $isFnb ? '5102' : '5101';
        $invAccCode = $isFnb ? '1141' : '1140';
        $taxOutAccCode = $isFnb ? '2111' : '2110';

        $bankAccId = $coaMap['1110'] ?? array_values($coaMap)[0];
        $salesAccId = $coaMap[$salesAccCode] ?? array_values($coaMap)[0];
        $cogsAccId = $coaMap[$cogsAccCode] ?? array_values($coaMap)[0];
        $invAccId = $coaMap[$invAccCode] ?? array_values($coaMap)[0];
        $taxOutAccId = $coaMap[$taxOutAccCode] ?? array_values($coaMap)[0];
        $serviceAccId = $coaMap['4103'] ?? null;

        $taxCodeRow = DB::table('tax_codes')->where('tenant_id', $tenant->id)->where('code', $isFnb ? 'PB1' : 'PPN')->first()
            ?? DB::table('tax_codes')->where('tenant_id', $tenant->id)->first();
        $taxRateRow = DB::table('tax_rates')->where('tenant_id', $tenant->id)->first();

        $mainStore = $stores->first();
        $cumBalances = [];

        foreach ($periods as $pCode => $p) {
            $monthDate = Carbon::parse($p->start_date);
            $txDate = $p->is_closed ? $p->end_date : '2026-11-10';

            // POS Sales Revenue Journal dari order aktual yang terekonsiliasi
            $actualOrders = DB::table('orders')
                ->where('tenant_id', $tenant->id)
                ->where('order_status', 'paid')
                ->whereBetween('order_date', [$p->start_date, $p->end_date])
                ->selectRaw('COALESCE(SUM(subtotal_amount - discount_amount), 0) as actual_sales, COALESCE(SUM(tax_amount), 0) as actual_tax, COALESCE(SUM(service_charge_amount), 0) as actual_service, COALESCE(SUM(order_amount), 0) as actual_total')
                ->first();

            $actualReturns = (float) DB::table('order_returns')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$p->start_date . ' 00:00:00', $p->end_date . ' 23:59:59'])
                ->sum('subtotal_returned');

            $salesAmount = ((float) ($actualOrders->actual_sales ?? 0)) - $actualReturns;
            $taxAmount = (float) ($actualOrders->actual_tax ?? 0);
            $serviceAmount = (float) ($actualOrders->actual_service ?? 0);
            $totalDebit = $salesAmount + $taxAmount + $serviceAmount;

            if ($totalDebit <= 0) {
                $salesAmount = 180000000.00 + (mt_rand(5, 25) * 1000000);
                $taxAmount = round($salesAmount * 0.10, 2);
                $serviceAmount = 0.00;
                $totalDebit = $salesAmount + $taxAmount;
            }

            $je1Id = DB::table('journal_entries')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore?->id,
                'journal_number' => 'JE-' . str_replace('-', '', $txDate) . '-SALES-' . $tenant->id,
                'accounting_period_id' => $p->id,
                'posting_date' => $txDate,
                'journal_type' => 'SYSTEM_AUTO',
                'status' => 'POSTED',
                'source_module' => 'SALES',
                'source_type' => 'POS_SALES',
                'source_number' => 'POS-BATCH-' . $pCode,
                'idempotency_key' => 'je-pos-sales-' . $tenant->id . '-' . $pCode,
                'total_debit' => $totalDebit,
                'total_credit' => $totalDebit,
                'notes' => "Pengakuan Penjualan Kasir POS Periode {$pCode}",
                'created_by_user_id' => $userId,
                'posted_by_user_id' => $userId,
                'posted_at' => $txDate . ' 23:59:00',
                'created_at' => $txDate . ' 23:59:00',
                'updated_at' => $txDate . ' 23:59:00',
            ]);

            // Lines: Debit Bank, Credit Sales, Credit Pajak PB1, Credit Service
            $lineSeq = 1;
            DB::table('journal_lines')->insert([
                'tenant_id' => $tenant->id,
                'journal_entry_id' => $je1Id,
                'line_number' => $lineSeq++,
                'account_id' => $bankAccId,
                'store_id' => $mainStore?->id,
                'debit_amount' => $totalDebit,
                'credit_amount' => 0.00,
                'description' => 'Penerimaan Kas/Bank Penjualan POS',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('journal_lines')->insert([
                'tenant_id' => $tenant->id,
                'journal_entry_id' => $je1Id,
                'line_number' => $lineSeq++,
                'account_id' => $salesAccId,
                'store_id' => $mainStore?->id,
                'debit_amount' => 0.00,
                'credit_amount' => $salesAmount,
                'description' => $isFnb ? 'Pendapatan Penjualan Menu F&B' : 'Pendapatan Penjualan Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $jl3 = DB::table('journal_lines')->insertGetId([
                'tenant_id' => $tenant->id,
                'journal_entry_id' => $je1Id,
                'line_number' => $lineSeq++,
                'account_id' => $taxOutAccId,
                'store_id' => $mainStore?->id,
                'debit_amount' => 0.00,
                'credit_amount' => $taxAmount,
                'description' => 'Pajak PPN / PB1 Restoran',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($serviceAmount > 0 && $serviceAccId) {
                DB::table('journal_lines')->insert([
                    'tenant_id' => $tenant->id,
                    'journal_entry_id' => $je1Id,
                    'line_number' => $lineSeq++,
                    'account_id' => $serviceAccId,
                    'store_id' => $mainStore?->id,
                    'debit_amount' => 0.00,
                    'credit_amount' => $serviceAmount,
                    'description' => 'Utang Service Charge Restoran',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Tax ledger entry (Compliant with chk_tle_direction: OUTPUT_TAX)
            if ($taxCodeRow && $taxRateRow) {
                DB::table('tax_ledger_entries')->insert([
                    'tenant_id' => $tenant->id,
                    'tax_code_id' => $taxCodeRow->id,
                    'tax_rate_id' => $taxRateRow->id,
                    'journal_entry_id' => $je1Id,
                    'journal_line_id' => $jl3,
                    'entry_number' => 'TAX-' . str_replace('-', '', $txDate) . '-' . Str::upper(Str::random(4)),
                    'transaction_date' => $txDate,
                    'tax_direction' => 'OUTPUT_TAX',
                    'tax_base_amount' => $salesAmount,
                    'applied_rate' => 10.0000,
                    'tax_amount' => $taxAmount,
                    'notes' => "Pajak Restoran Masa {$pCode}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // HPP Journal (Debit HPP, Credit Inventory)
            $actualCogs = DB::table('orders as o')
                ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
                ->where('o.tenant_id', $tenant->id)
                ->where('o.order_status', 'paid')
                ->whereBetween('o.order_date', [$p->start_date, $p->end_date])
                ->selectRaw('COALESCE(SUM(oi.hpp_snapshot * oi.qty), 0) as total_cogs')
                ->first();

            $cogsAmount = (float) ($actualCogs->total_cogs ?? 0);
            if ($cogsAmount <= 0) {
                $cogsAmount = round($salesAmount * 0.38, 2);
            }

            $je2Id = DB::table('journal_entries')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore?->id,
                'journal_number' => 'JE-' . str_replace('-', '', $txDate) . '-HPP-' . $tenant->id,
                'accounting_period_id' => $p->id,
                'posting_date' => $txDate,
                'journal_type' => 'SYSTEM_AUTO',
                'status' => 'POSTED',
                'source_module' => 'INVENTORY',
                'source_type' => 'COGS_RECOGNITION',
                'source_number' => 'COGS-BATCH-' . $pCode,
                'idempotency_key' => 'je-cogs-' . $tenant->id . '-' . $pCode,
                'total_debit' => $cogsAmount,
                'total_credit' => $cogsAmount,
                'notes' => "Pengakuan Beban Pokok Penjualan (HPP) {$pCode}",
                'created_by_user_id' => $userId,
                'posted_by_user_id' => $userId,
                'posted_at' => $txDate . ' 23:59:00',
                'created_at' => $txDate . ' 23:59:00',
                'updated_at' => $txDate . ' 23:59:00',
            ]);

            DB::table('journal_lines')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'journal_entry_id' => $je2Id,
                    'line_number' => 1,
                    'account_id' => $cogsAccId,
                    'store_id' => $mainStore?->id,
                    'debit_amount' => $cogsAmount,
                    'credit_amount' => 0.00,
                    'description' => $isFnb ? 'Beban Pokok Penjualan (HPP) F&B' : 'Beban Pokok Penjualan (HPP) Retail',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'journal_entry_id' => $je2Id,
                    'line_number' => 2,
                    'account_id' => $invAccId,
                    'store_id' => $mainStore?->id,
                    'debit_amount' => 0.00,
                    'credit_amount' => $cogsAmount,
                    'description' => $isFnb ? 'Pengurangan Persediaan Bahan Baku F&B' : 'Pengurangan Persediaan Produk Retail',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Inventory Valuation Snapshot
            $pFirst = DB::table('products')->where('tenant_id', $tenant->id)->first();
            if ($pFirst) {
                foreach ($stores as $st) {
                    DB::table('inventory_valuation_snapshots')->insert([
                        'tenant_id' => $tenant->id,
                        'accounting_period_id' => $p->id,
                        'store_id' => $st->id,
                        'snapshot_date' => $p->end_date,
                        'item_type' => 'product',
                        'item_id' => $pFirst->id,
                        'closing_qty' => 2450.50,
                        'average_unit_cost' => 19790.00,
                        'total_valuation_amount' => 48500000.00,
                        'gl_account_id' => $invAccId,
                        'gl_balance_amount' => 48500000.00,
                        'variance_amount' => 0.00,
                        'reconciliation_status' => 'MATCHED',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // Account Period Balances Calculation
        $allAccounts = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->pluck('id');
        foreach ($periods as $pCode => $p) {
            foreach ($allAccounts as $accId) {
                $lines = DB::table('journal_lines')
                    ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                    ->where('journal_lines.tenant_id', $tenant->id)
                    ->where('journal_lines.account_id', $accId)
                    ->where('journal_entries.accounting_period_id', $p->id)
                    ->selectRaw('COALESCE(SUM(debit_amount), 0) as dr, COALESCE(SUM(credit_amount), 0) as cr')
                    ->first();

                $periodDr = (float) ($lines?->dr ?? 0);
                $periodCr = (float) ($lines?->cr ?? 0);
                $openingDr = $cumBalances[$accId]['dr'] ?? 0.0;
                $openingCr = $cumBalances[$accId]['cr'] ?? 0.0;
                $closingDr = $openingDr + $periodDr;
                $closingCr = $openingCr + $periodCr;
                $cumBalances[$accId] = ['dr' => $closingDr, 'cr' => $closingCr];

                DB::table('account_period_balances')->insert([
                    'tenant_id' => $tenant->id,
                    'accounting_period_id' => $p->id,
                    'account_id' => $accId,
                    'store_id' => $mainStore?->id,
                    'store_scope_key' => 0,
                    'opening_debit' => $openingDr,
                    'opening_credit' => $openingCr,
                    'period_debit' => $periodDr,
                    'period_credit' => $periodCr,
                    'closing_debit' => $closingDr,
                    'closing_credit' => $closingCr,
                    'net_movement' => $periodDr - $periodCr,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Outbox event samples
        DB::table('financial_event_outboxes')->insert([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore?->id,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'FINANCIAL_POSTING_COMMITTED',
            'source_module' => 'FINANCE',
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => $je1Id,
            'source_number' => 'JE-202611-BATCH',
            'idempotency_key' => 'fe-outbox-' . $tenant->id . '-202611',
            'payload' => json_encode(['amount' => $totalDebit, 'status' => 'POSTED']),
            'status' => 'PROCESSED',
            'occurred_at' => now(),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('accounting_receivable_outboxes')->insert([
            'event_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore?->id,
            'event_type' => 'AR_INVOICE_SYNCHRONIZED',
            'idempotency_key' => 'ar-outbox-' . $tenant->id . '-202611',
            'source_document_type' => 'CUSTOMER_INVOICE',
            'source_document_id' => 1,
            'source_document_number' => 'INV-202611-001',
            'status' => 'processed_by_m7',
            'payload' => json_encode(['synced' => true, 'timestamp' => now()->toIso8601String()]),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedApSubledger(Tenant $tenant, $stores, array $cashBankMap, int $userId): void
    {
        if (DB::table('ap_ledger_entries')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $supplier = DB::table('suppliers')->where('tenant_id', $tenant->id)->first();
        $supplierInvoice = DB::table('supplier_invoices')->where('tenant_id', $tenant->id)->first();
        $store = $stores->first();

        if (! $supplierInvoice && $supplier && $store) {
            $invId = DB::table('supplier_invoices')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'supplier_id' => $supplier->id,
                'internal_invoice_number' => 'SI-GR-' . ($store->code ?? 'STORE') . '-261025-0001',
                'supplier_invoice_number' => 'INV-SUP-' . $tenant->id . '-20261025',
                'normalized_supplier_invoice_number' => 'INV-SUP-' . $tenant->id . '-20261025',
                'invoice_date' => '2026-10-25',
                'due_date' => '2026-11-24',
                'currency_code' => 'IDR',
                'exchange_rate' => 1.000000,
                'subtotal_amount' => 76576576.00,
                'tax_amount' => 8423424.00,
                'total_amount' => 85000000.00,
                'paid_amount' => 85000000.00,
                'outstanding_amount' => 0.00,
                'status' => 'approved_payable',
                'settlement_status' => 'paid',
                'created_at' => '2026-10-25 10:00:00',
                'updated_at' => '2026-11-02 11:00:00',
            ]);
            $supplierInvoice = DB::table('supplier_invoices')->where('id', $invId)->first();
        }

        if ($supplier && $supplierInvoice) {
            $amount = (float) $supplierInvoice->total_amount;

            $payment = DB::table('ap_payments')->where('tenant_id', $tenant->id)->first();
            if ($payment) {
                $paymentId = $payment->id;
            } else {
                $paymentId = DB::table('ap_payments')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'supplier_id' => $supplier->id,
                    'cash_bank_account_id' => $cashBankMap['bca'],
                    'payment_number' => 'APPAY-' . $tenant->id . '-' . date('Ymd') . '-' . Str::upper(Str::random(4)),
                    'payment_date' => '2026-11-02',
                    'payment_method' => 'BANK_TRANSFER',
                    'currency_code' => 'IDR',
                    'exchange_rate' => 1.000000,
                    'total_payment_amount' => $amount,
                    'allocated_amount' => $amount,
                    'status' => 'POSTED',
                    'notes' => 'Pelunasan faktur supplier bahan baku via BCA',
                    'idempotency_key' => 'appay-' . $tenant->id . '-' . $supplierInvoice->id,
                    'created_by_user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (DB::table('ap_payment_allocations')->where('tenant_id', $tenant->id)->count() === 0) {
                DB::table('ap_payment_allocations')->insert([
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'ap_payment_id' => $paymentId,
                    'supplier_invoice_id' => $supplierInvoice->id,
                    'allocated_amount' => $amount,
                    'discount_taken_amount' => 0.00,
                    'status' => 'POSTED',
                    'idempotency_key' => 'ap-alloc-' . $tenant->id . '-' . $supplierInvoice->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('ap_ledger_entries')->insert([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'supplier_id' => $supplier->id,
                'supplier_invoice_id' => $supplierInvoice->id,
                'ap_payment_id' => null,
                'entry_number' => 'APL-INV-' . $tenant->id . '-' . $supplierInvoice->id,
                'effective_date' => '2026-10-28',
                'entry_type' => 'INVOICE_CREDIT',
                'debit_amount' => 0.00,
                'credit_amount' => $amount,
                'running_balance' => $amount,
                'idempotency_key' => 'apl-inv-' . $tenant->id . '-' . $supplierInvoice->id,
                'description' => 'Faktur Pembelian Supplier',
                'created_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ap_ledger_entries')->insert([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'supplier_id' => $supplier->id,
                'supplier_invoice_id' => null,
                'ap_payment_id' => $paymentId,
                'entry_number' => 'APL-PAY-' . $tenant->id . '-' . $paymentId,
                'effective_date' => '2026-11-02',
                'entry_type' => 'PAYMENT_DEBIT',
                'debit_amount' => $amount,
                'credit_amount' => 0.00,
                'running_balance' => 0.00,
                'idempotency_key' => 'apl-pay-' . $tenant->id . '-' . $paymentId,
                'description' => 'Pelunasan Faktur Supplier',
                'created_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedB2bSalesAndCrm(Tenant $tenant, $stores, int $userId): void
    {
        if (DB::table('customer_groups')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $customers = DB::table('customers')->where('tenant_id', $tenant->id)->get();
        $products = DB::table('products')->where('tenant_id', $tenant->id)->get();
        $store = $stores->first();
        $targetCust = $customers->first();
        $secondCust = $customers->skip(1)->first();

        // 1. Customer Groups
        $groups = [
            ['code' => 'RETAIL-' . $tenant->id, 'name' => 'Pelanggan Walk-In Retail', 'disc' => 0.00],
            ['code' => 'CORP-' . $tenant->id, 'name' => 'Corporate Catering & Event B2B', 'disc' => 10.00],
            ['code' => 'RESELLER-' . $tenant->id, 'name' => 'Wholesale Cafe Partner / Reseller', 'disc' => 15.00],
            ['code' => 'VIP-' . $tenant->id, 'name' => 'VIP Apeiron Gold Members', 'disc' => 5.00],
        ];
        foreach ($groups as $g) {
            DB::table('customer_groups')->insert([
                'tenant_id' => $tenant->id,
                'code' => $g['code'],
                'name' => $g['name'],
                'description' => 'Grup pelanggan resmi Apeiron System',
                'default_discount_rate' => $g['disc'],
                'is_b2b_default' => str_starts_with($g['code'], 'CORP') ? 1 : 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($targetCust) {
            // 2. Customer Addresses (Compliant with chk_ca_address_type: BILLING, SHIPPING, BOTH)
            $addrId = DB::table('customer_addresses')->insertGetId([
                'tenant_id' => $tenant->id,
                'customer_id' => $targetCust->id,
                'address_type' => 'BOTH',
                'label' => 'Kantor Pusat Menara Sudirman',
                'recipient_name' => $targetCust->name,
                'phone' => $targetCust->phone ?? '081298765432',
                'address_line1' => 'Jl. Jend. Sudirman Kav. 21, Lantai 18',
                'city' => 'Jakarta Selatan',
                'province' => 'DKI Jakarta',
                'postal_code' => '12190',
                'country_code' => 'ID',
                'is_default_billing' => 1,
                'is_default_shipping' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Customer Contacts
            DB::table('customer_contacts')->insert([
                'tenant_id' => $tenant->id,
                'customer_id' => $targetCust->id,
                'name' => 'Bapak Hendra Gunawan',
                'role_title' => 'Head of Procurement & Facilities',
                'email' => 'hendra.procurement@example.corp',
                'phone' => '08119827364',
                'is_primary' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Customer Merge Logs
            if ($secondCust) {
                DB::table('customer_merge_logs')->insert([
                    'tenant_id' => $tenant->id,
                    'source_customer_id' => $secondCust->id,
                    'target_customer_id' => $targetCust->id,
                    'merged_by_user_id' => $userId,
                    'reason' => 'Penggabungan akun duplikat pendaftaran walk-in dan online loyalty',
                    'reassigned_records_json' => json_encode(['points' => 150, 'orders' => 3]),
                    'created_at' => now(),
                ]);
            }

            // 5. Customer Credit Accounts & Logs
            DB::table('customer_credit_accounts')->insert([
                'tenant_id' => $tenant->id,
                'customer_id' => $targetCust->id,
                'credit_limit' => 35000000.00,
                'payment_terms_days' => 30,
                'grace_period_days' => 7,
                'credit_status' => 'ACTIVE',
                'risk_rating' => 'LOW',
                'reserved_exposure' => 0.00,
                'invoiced_exposure' => 3875000.00,
                'unapplied_credit_balance' => 0.00,
                'last_reconciled_at' => now(),
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('customer_credit_logs')->insert([
                'tenant_id' => $tenant->id,
                'customer_id' => $targetCust->id,
                'event_type' => 'LIMIT_APPROVAL',
                'old_values_json' => json_encode(['credit_limit' => 0]),
                'new_values_json' => json_encode(['credit_limit' => 35000000]),
                'reference_type' => 'CREDIT_APPLICATION',
                'actor_user_id' => $userId,
                'reason' => 'Persetujuan fasilitas B2B NET 30 catering bulanan',
                'created_at' => now(),
            ]);

            // 6. Quotation & Lines
            $p1 = $products->first();
            $quoteId = DB::table('quotations')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'quotation_number' => 'QUO-202610-' . $tenant->id . '-001',
                'customer_id' => $targetCust->id,
                'customer_address_id' => $addrId,
                'issue_date' => '2026-10-10',
                'valid_until' => '2026-10-31',
                'status' => 'CONVERTED',
                'subtotal' => 12500000.00,
                'discount_amount' => 0.00,
                'tax_amount' => 1375000.00,
                'total_amount' => 13875000.00,
                'customer_snapshot_json' => json_encode(['name' => $targetCust->name, 'phone' => $targetCust->phone ?? '081298765432']),
                'created_by_user_id' => $userId,
                'created_at' => '2026-10-10 10:00:00',
                'updated_at' => '2026-10-12 11:00:00',
            ]);

            DB::table('quotation_lines')->insert([
                'tenant_id' => $tenant->id,
                'quotation_id' => $quoteId,
                'line_number' => 1,
                'product_id' => $p1?->id ?? 1,
                'quantity' => 250.0000,
                'base_unit_price' => 50000.00,
                'unit_price' => 50000.00,
                'line_total' => 12500000.00,
                'created_at' => '2026-10-10 10:00:00',
                'updated_at' => '2026-10-10 10:00:00',
            ]);

            // 7. Sales Order & Lines
            $soId = DB::table('sales_orders')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'so_number' => 'SO-202610-' . $tenant->id . '-001',
                'quotation_id' => $quoteId,
                'customer_id' => $targetCust->id,
                'billing_address_id' => $addrId,
                'shipping_address_id' => $addrId,
                'order_date' => '2026-10-12',
                'status' => 'CONFIRMED',
                'fulfillment_status' => 'FULFILLED',
                'invoicing_status' => 'FULLY_INVOICED',
                'subtotal' => 12500000.00,
                'tax_amount' => 1375000.00,
                'total_amount' => 13875000.00,
                'customer_snapshot_json' => json_encode(['name' => $targetCust->name, 'phone' => $targetCust->phone ?? '081298765432']),
                'created_by_user_id' => $userId,
                'created_at' => '2026-10-12 11:00:00',
                'updated_at' => '2026-10-12 11:00:00',
            ]);

            $soLineId = DB::table('sales_order_lines')->insertGetId([
                'tenant_id' => $tenant->id,
                'sales_order_id' => $soId,
                'line_number' => 1,
                'product_id' => $p1?->id ?? 1,
                'ordered_qty' => 250.0000,
                'base_unit_price' => 50000.00,
                'unit_price' => 50000.00,
                'line_total' => 12500000.00,
                'created_at' => '2026-10-12 11:00:00',
                'updated_at' => '2026-10-12 11:00:00',
            ]);

            // 8. Customer Invoice & Lines
            $invId = DB::table('customer_invoices')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'sales_order_id' => $soId,
                'customer_id' => $targetCust->id,
                'invoice_number' => 'INV-B2B-' . $tenant->id . '-202610-001',
                'tax_invoice_serial_number' => '010.002-26.' . $tenant->id . '9201928',
                'invoice_date' => '2026-10-15',
                'due_date' => '2026-11-14',
                'status' => 'PARTIALLY_PAID',
                'subtotal' => 12500000.00,
                'tax_amount' => 1375000.00,
                'shipping_amount' => 0.00,
                'total_amount' => 13875000.00,
                'paid_amount' => 10000000.00,
                'credit_applied_amount' => 0.00,
                'balance_due' => 3875000.00,
                'customer_snapshot_json' => json_encode(['name' => $targetCust->name]),
                'created_at' => '2026-10-15 14:00:00',
                'updated_at' => '2026-11-01 10:00:00',
            ]);

            DB::table('customer_invoice_lines')->insert([
                'tenant_id' => $tenant->id,
                'customer_invoice_id' => $invId,
                'sales_order_line_id' => $soLineId,
                'product_id' => $p1?->id ?? 1,
                'line_number' => 1,
                'description' => 'Catering Coffee & Pastry Package B2B',
                'quantity' => 250.0000,
                'unit_price' => 50000.00,
                'tax_rate' => 11.00,
                'tax_amount' => 1375000.00,
                'line_total' => 12500000.00,
                'created_at' => '2026-10-15 14:00:00',
                'updated_at' => '2026-10-15 14:00:00',
            ]);

            // 9. Sales Credit Note & Application
            $scnId = DB::table('sales_credit_notes')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'customer_id' => $targetCust->id,
                'credit_note_number' => 'SCN-' . $tenant->id . '-202610-001',
                'origin_customer_invoice_id' => $invId,
                'issue_date' => '2026-10-18',
                'reason_code' => 'DISCOUNT_ADJUSTMENT',
                'subtotal' => 500000.00,
                'tax_amount' => 55000.00,
                'total_amount' => 555000.00,
                'applied_amount' => 555000.00,
                'remaining_amount' => 0.00,
                'status' => 'ISSUED',
                'notes' => 'Penyesuaian diskon volume kemitraan korporasi',
                'created_at' => '2026-10-18 10:00:00',
                'updated_at' => '2026-10-18 11:00:00',
            ]);

            DB::table('sales_credit_note_applications')->insert([
                'tenant_id' => $tenant->id,
                'sales_credit_note_id' => $scnId,
                'customer_invoice_id' => $invId,
                'applied_amount' => 555000.00,
                'applied_by_user_id' => $userId,
                'applied_at' => '2026-10-18 11:00:00',
                'idempotency_key' => 'scna-' . $tenant->id . '-1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 10. AR Ledger Entries, AR Receipts & AR Payment Allocations
            $receiptId = DB::table('ar_receipts')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'customer_id' => $targetCust->id,
                'receipt_number' => 'ARR-' . $tenant->id . '-202611-001',
                'receipt_date' => '2026-11-01',
                'total_received_amount' => 10000000.00,
                'total_allocated_amount' => 10000000.00,
                'payment_method' => 'bank_transfer',
                'status' => 'POSTED',
                'notes' => 'Pembayaran termin 1 catering via transfer BCA',
                'created_by_user_id' => $userId,
                'created_at' => '2026-11-01 10:00:00',
                'updated_at' => '2026-11-01 10:00:00',
            ]);

            DB::table('ar_payment_allocations')->insert([
                'tenant_id' => $tenant->id,
                'customer_id' => $targetCust->id,
                'customer_invoice_id' => $invId,
                'ar_receipt_id' => $receiptId,
                'allocated_amount' => 10000000.00,
                'allocated_at' => '2026-11-01 10:00:00',
                'allocated_by_user_id' => $userId,
                'idempotency_key' => 'arpa-' . $tenant->id . '-1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ar_ledger_entries')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'customer_id' => $targetCust->id,
                    'customer_invoice_id' => $invId,
                    'entry_date' => '2026-10-15 14:00:00',
                    'entry_type' => 'INVOICE_DEBIT',
                    'reference_type' => 'CUSTOMER_INVOICE',
                    'reference_id' => $invId,
                    'debit_amount' => 13875000.00,
                    'credit_amount' => 0.00,
                    'running_balance_snapshot' => 13875000.00,
                    'actor_user_id' => $userId,
                    'notes' => 'Tagihan catering B2B',
                    'created_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'customer_id' => $targetCust->id,
                    'customer_invoice_id' => $invId,
                    'entry_date' => '2026-11-01 10:00:00',
                    'entry_type' => 'PAYMENT_CREDIT',
                    'reference_type' => 'AR_RECEIPT',
                    'reference_id' => $receiptId,
                    'debit_amount' => 0.00,
                    'credit_amount' => 10000000.00,
                    'running_balance_snapshot' => 3875000.00,
                    'actor_user_id' => $userId,
                    'notes' => 'Pembayaran termin 1 via BCA',
                    'created_at' => now(),
                ],
            ]);

            DB::table('customer_credit_accounts')
                ->where('tenant_id', $tenant->id)
                ->where('customer_id', $targetCust->id)
                ->update(['invoiced_exposure' => 3875000.00]);
        }
    }

    private function seedCustomerCreditAndInstruments(Tenant $tenant, $stores, int $userId): void
    {
        if (DB::table('store_credit_accounts')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $customer = DB::table('customers')->where('tenant_id', $tenant->id)->first();
        $store = $stores->first();

        if ($customer) {
            // 1. Store Credit Accounts & Ledgers (Compliant with chk_scl_entry_type: OVERPAYMENT_ISSUE)
            $scId = DB::table('store_credit_accounts')->insertGetId([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'current_balance' => 250000.00,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('store_credit_ledgers')->insert([
                'tenant_id' => $tenant->id,
                'store_credit_account_id' => $scId,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'entry_type' => 'OVERPAYMENT_ISSUE',
                'amount' => 250000.00,
                'balance_after' => 250000.00,
                'reference_type' => 'MANUAL_GRANT',
                'idempotency_key' => 'scl-grant-' . $tenant->id . '-' . $customer->id,
                'actor_user_id' => $userId,
                'created_at' => now(),
            ]);

            // 2. Gift Cards & Ledgers (Compliant with chk_gcl_entry_type: ISSUE, REDEEM)
            $gcCardNum = 'GC-' . $tenant->id . '-2026-' . Str::upper(Str::random(6));
            $gcId = DB::table('gift_cards')->insertGetId([
                'tenant_id' => $tenant->id,
                'issued_store_id' => $store->id,
                'card_number' => $gcCardNum,
                'pin_hash' => Hash::make('123456'),
                'customer_id' => $customer->id,
                'initial_balance' => 500000.00,
                'current_balance' => 350000.00,
                'status' => 'ACTIVE',
                'expires_at' => '2027-12-31 23:59:59',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('gift_card_ledgers')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'gift_card_id' => $gcId,
                    'store_id' => $store->id,
                    'entry_type' => 'ISSUE',
                    'amount' => 500000.00,
                    'balance_after' => 500000.00,
                    'idempotency_key' => 'gcl-act-' . $gcId,
                    'actor_user_id' => $userId,
                    'created_at' => '2026-09-01 10:00:00',
                ],
                [
                    'tenant_id' => $tenant->id,
                    'gift_card_id' => $gcId,
                    'store_id' => $store->id,
                    'entry_type' => 'REDEEM',
                    'amount' => 150000.00,
                    'balance_after' => 350000.00,
                    'idempotency_key' => 'gcl-red-' . $gcId,
                    'actor_user_id' => $userId,
                    'created_at' => '2026-10-20 15:30:00',
                ],
            ]);
        }
    }

    private function seedNineEnterpriseTables(Tenant $tenant, $stores, $users, $employees, int $userId): void
    {
        $tenantId = (int) $tenant->id;
        $isRetail = ($tenant->primary_industry === 'retail');
        $now = now();
        $mainStore = $stores->first();
        $storeId = $mainStore?->id;

        // 1. Departments & Job Titles
        $deptDefs = [
            ['code' => 'DEPT-OPS', 'name' => 'Store Operations', 'desc' => 'Operasional Toko & Layanan Pelanggan'],
            ['code' => 'DEPT-KIT', 'name' => $isRetail ? 'Merchandising & Display' : 'Kitchen & Bar', 'desc' => $isRetail ? 'Visual Merchandising & Tata Letak Produk' : 'Produksi Makanan & Minuman Bar'],
            ['code' => 'DEPT-LOG', 'name' => 'Logistics & Warehousing', 'desc' => 'Pengelolaan Gudang, Logistik & Pengiriman'],
            ['code' => 'DEPT-FIN', 'name' => 'Finance & Accounting', 'desc' => 'Keuangan, Pajak, dan Pembukuan Akuntansi'],
            ['code' => 'DEPT-EXEC', 'name' => 'Executive & Admin', 'desc' => 'Manajemen Eksekutif & General Affairs'],
        ];

        $deptMap = [];
        foreach ($deptDefs as $d) {
            DB::table('departments')->updateOrInsert(
                ['tenant_id' => $tenantId, 'department_code' => $d['code']],
                [
                    'name' => $d['name'],
                    'description' => $d['desc'],
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $deptMap[$d['code']] = DB::table('departments')->where('tenant_id', $tenantId)->where('department_code', $d['code'])->value('id');
        }

        $titleDefs = [
            ['code' => 'DIR', 'name' => 'Direktur Operasional', 'dept' => 'DEPT-EXEC', 'grade' => 4, 'desc' => 'Pimpinan Tertinggi Eksekutif Operasional'],
            ['code' => 'MGR-OPS', 'name' => 'Store Manager', 'dept' => 'DEPT-OPS', 'grade' => 3, 'desc' => 'Kepala Toko / Outlet Manager'],
            ['code' => 'SPV-OPS', 'name' => 'Supervisor Shift', 'dept' => 'DEPT-OPS', 'grade' => 2, 'desc' => 'Pengawas Operasional Shift'],
            ['code' => 'KSR-OPS', 'name' => 'Kasir Utama', 'dept' => 'DEPT-OPS', 'grade' => 1, 'desc' => 'Operator Kasir & Point of Sale'],
            ['code' => 'STF-OPS', 'name' => $isRetail ? 'Pramuniaga Toko' : 'Staff Pelayanan / Waiter', 'dept' => 'DEPT-OPS', 'grade' => 1, 'desc' => 'Staf Pelayanan Toko'],
            ['code' => 'CHEF-BAR', 'name' => $isRetail ? 'Lead Visual Merchandiser' : 'Head Chef & Barista Lead', 'dept' => 'DEPT-KIT', 'grade' => 3, 'desc' => 'Penanggung Jawab Menu / Display'],
            ['code' => 'COOK-BAR', 'name' => $isRetail ? 'Staff Display & Inventory' : 'Line Cook & Barista', 'dept' => 'DEPT-KIT', 'grade' => 1, 'desc' => 'Operator Dapur / Display'],
            ['code' => 'FIN-ACC', 'name' => 'Finance & Tax Specialist', 'dept' => 'DEPT-FIN', 'grade' => 2, 'desc' => 'Staf Keuangan & Pelaporan Pajak'],
            ['code' => 'LOG-WHS', 'name' => 'Warehouse & Inventory Specialist', 'dept' => 'DEPT-LOG', 'grade' => 2, 'desc' => 'Pengelola Stok & Penerimaan Barang'],
        ];

        $titleMap = [];
        foreach ($titleDefs as $t) {
            $deptId = $deptMap[$t['dept']] ?? $deptMap['DEPT-OPS'];
            DB::table('job_titles')->updateOrInsert(
                ['tenant_id' => $tenantId, 'title_code' => $t['code']],
                [
                    'department_id' => $deptId,
                    'name' => $t['name'],
                    'grade_level' => $t['grade'],
                    'description' => $t['desc'],
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $titleMap[$t['code']] = DB::table('job_titles')->where('tenant_id', $tenantId)->where('title_code', $t['code'])->value('id');
        }

        // Link employees to departments and job titles if not already linked
        $empList = DB::table('employees')->where('tenant_id', $tenantId)->get();
        foreach ($empList as $idx => $emp) {
            $assignedTitleCode = match ($idx % 5) {
                0 => 'MGR-OPS',
                1 => 'SPV-OPS',
                2 => 'KSR-OPS',
                3 => 'CHEF-BAR',
                default => 'STF-OPS',
            };
            $assignedTitleId = $titleMap[$assignedTitleCode] ?? array_values($titleMap)[0];
            $assignedDeptId = DB::table('job_titles')->where('id', $assignedTitleId)->value('department_id') ?? $deptMap['DEPT-OPS'];

            DB::table('employees')->where('id', $emp->id)->update([
                'department_id' => $assignedDeptId,
                'job_title_id' => $assignedTitleId,
                'updated_at' => $now,
            ]);
        }

        // 2. Employee Tax Profiles
        $ptkpStatuses = ['TK_0', 'TK_1', 'K_0', 'K_1', 'K_2'];
        foreach ($empList as $idx => $emp) {
            $ptkp = $ptkpStatuses[$idx % count($ptkpStatuses)];
            $npwp = sprintf('81.%03d.%03d.%1d-%03d.000', ($emp->id * 37) % 999, ($emp->id * 71) % 999, ($emp->id % 9), ($emp->id * 13) % 999);

            DB::table('employee_tax_profiles')->updateOrInsert(
                ['tenant_id' => $tenantId, 'employee_id' => $emp->id],
                [
                    'tax_identification_number' => $npwp,
                    'ptkp_status' => $ptkp,
                    'calculation_method' => 'GROSS',
                    'is_eligible_for_dtp' => 0,
                    'effective_from' => '2026-01-01',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // 3. Store Targets (2026-09, 2026-10, 2026-11)
        $targetPeriods = ['2026-09', '2026-10', '2026-11'];
        $baseTargets = [
            '2026-09' => 380000000.00,
            '2026-10' => 420000000.00,
            '2026-11' => 450000000.00,
        ];
        foreach ($stores as $store) {
            foreach ($targetPeriods as $p) {
                $targetAmount = $baseTargets[$p] + (($store->id % 4) * 25000000.00);
                DB::table('store_targets')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'store_id' => $store->id, 'target_period' => $p],
                    [
                        'company_id' => $tenantId,
                        'target_net_sales' => $targetAmount,
                        'target_gross_margin_pct' => $isRetail ? 45.00 : 65.00,
                        'max_shrinkage_pct' => 1.50,
                        'max_labor_cost_pct' => 20.00,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }

        // 4. Product Lots (FEFO Tracking with near-expired lot for testing)
        $sampleProducts = DB::table('products')->where('tenant_id', $tenantId)->orderBy('id')->limit(5)->get();
        if ($sampleProducts->isNotEmpty()) {
            $lots = [
                [
                    'lot_number' => '#LOT-' . $tenantId . '-CAN-202611',
                    'expiry_date' => '2026-11-25',
                    'received_date' => '2026-08-15',
                    'initial_qty' => 100,
                    'current_qty' => 65,
                    'cost' => 45000.00,
                    'status' => 'active',
                    'notes' => 'Near Expiry H-14 FEFO Alert Test Lot',
                ],
                [
                    'lot_number' => '#LOT-' . $tenantId . '-BAT-202612',
                    'expiry_date' => '2026-12-31',
                    'received_date' => '2026-09-01',
                    'initial_qty' => 250,
                    'current_qty' => 210,
                    'cost' => 38000.00,
                    'status' => 'active',
                    'notes' => 'Regular Batch 2026-Q4',
                ],
                [
                    'lot_number' => '#LOT-' . $tenantId . '-BAT-202703',
                    'expiry_date' => '2027-03-31',
                    'received_date' => '2026-10-01',
                    'initial_qty' => 500,
                    'current_qty' => 480,
                    'cost' => 35000.00,
                    'status' => 'active',
                    'notes' => 'Fresh Stock Q1-2027',
                ],
                [
                    'lot_number' => '#LOT-' . $tenantId . '-BAT-202706',
                    'expiry_date' => '2027-06-30',
                    'received_date' => '2026-10-20',
                    'initial_qty' => 400,
                    'current_qty' => 400,
                    'cost' => 35000.00,
                    'status' => 'active',
                    'notes' => 'Warehouse Buffer Stock',
                ],
                [
                    'lot_number' => '#LOT-' . $tenantId . '-EXP-202610',
                    'expiry_date' => '2026-10-15',
                    'received_date' => '2026-07-01',
                    'initial_qty' => 50,
                    'current_qty' => 0,
                    'cost' => 40000.00,
                    'status' => 'depleted',
                    'notes' => 'Batch Habis Terjual',
                ],
            ];

            foreach ($lots as $idx => $lot) {
                $prod = $sampleProducts[$idx % $sampleProducts->count()];
                $existingLot = DB::table('product_lots')->where('tenant_id', $tenantId)->where('lot_number', $lot['lot_number'])->first();
                if (! $existingLot) {
                    DB::table('product_lots')->insert([
                        'tenant_id' => $tenantId,
                        'company_id' => $tenantId,
                        'store_id' => $storeId,
                        'product_id' => $prod->id,
                        'lot_number' => $lot['lot_number'],
                        'expiry_date' => $lot['expiry_date'],
                        'received_date' => $lot['received_date'],
                        'initial_quantity' => $lot['initial_qty'],
                        'current_quantity' => $lot['current_qty'],
                        'unit_cost' => $lot['cost'],
                        'status' => $lot['status'],
                        'notes' => $lot['notes'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // 5. Ingredient Recipes (Kitchen Prep Items BOM)
        $ingDefs = [
            ['code' => 'ING-COFFEE-BEAN-' . $tenantId, 'name' => 'Biji Kopi Arabica Gayo', 'unit' => 'gram', 'cost' => 250.00],
            ['code' => 'ING-PREP-ESPRESSO-' . $tenantId, 'name' => 'Espresso Base Prep Batch', 'unit' => 'shot', 'cost' => 4500.00],
            ['code' => 'ING-SUGAR-AREN-' . $tenantId, 'name' => 'Gula Aren Organik Cair', 'unit' => 'ml', 'cost' => 50.00],
            ['code' => 'ING-PREP-SYRUP-' . $tenantId, 'name' => 'Sugar Syrup Prep Batch', 'unit' => 'bottle', 'cost' => 15000.00],
            ['code' => 'ING-WAGYU-RAW-' . $tenantId, 'name' => 'Daging Wagyu Raw Cut', 'unit' => 'gram', 'cost' => 600.00],
            ['code' => 'ING-PREP-WAGYU-' . $tenantId, 'name' => 'Marinated Wagyu Prep Batch', 'unit' => 'portion', 'cost' => 75000.00],
        ];

        $ingMap = [];
        foreach ($ingDefs as $ingDef) {
            $existing = DB::table('ingredients')->where('tenant_id', $tenantId)->where('ingredient_code', $ingDef['code'])->first();
            if ($existing) {
                $ingMap[$ingDef['code']] = $existing->id;
            } else {
                $ingMap[$ingDef['code']] = DB::table('ingredients')->insertGetId([
                    'tenant_id' => $tenantId,
                    'ingredient_code' => $ingDef['code'],
                    'name' => $ingDef['name'],
                    'unit' => $ingDef['unit'],
                    'current_stock' => 50000,
                    'cost_per_unit' => $ingDef['cost'],
                    'reorder_level' => 1000,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $recipes = [
            [
                'parent' => 'ING-PREP-ESPRESSO-' . $tenantId,
                'comp' => 'ING-COFFEE-BEAN-' . $tenantId,
                'qty' => 18.0000,
                'unit' => 'gram',
                'notes' => '18g biji kopi per shot espresso base',
            ],
            [
                'parent' => 'ING-PREP-SYRUP-' . $tenantId,
                'comp' => 'ING-SUGAR-AREN-' . $tenantId,
                'qty' => 500.0000,
                'unit' => 'ml',
                'notes' => '500ml gula aren organik per botol sirup',
            ],
            [
                'parent' => 'ING-PREP-WAGYU-' . $tenantId,
                'comp' => 'ING-WAGYU-RAW-' . $tenantId,
                'qty' => 200.0000,
                'unit' => 'gram',
                'notes' => '200g potongan wagyu per porsi marinate',
            ],
        ];

        foreach ($recipes as $r) {
            $parentId = $ingMap[$r['parent']];
            $compId = $ingMap[$r['comp']];
            $exists = DB::table('ingredient_recipes')
                ->where('tenant_id', $tenantId)
                ->where('parent_ingredient_id', $parentId)
                ->where('component_ingredient_id', $compId)
                ->exists();

            if (! $exists) {
                DB::table('ingredient_recipes')->insert([
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'store_id' => $storeId,
                    'parent_ingredient_id' => $parentId,
                    'component_ingredient_id' => $compId,
                    'quantity_required' => $r['qty'],
                    'unit' => $r['unit'],
                    'notes' => $r['notes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 6. Tax Submissions (6 SPT Masa per tenant: PPN & PPh 21 for 2026-08, 2026-09, 2026-10)
        $taxPeriods = [
            ['period' => '2026-08', 'type' => 'PPN', 'tax_type' => 'SPT Masa PPN 1111', 'sub_date' => '2026-09-28 14:00:00'],
            ['period' => '2026-08', 'type' => 'PPH21', 'tax_type' => 'SPT Masa PPh Pasal 21/26', 'sub_date' => '2026-09-18 10:30:00'],
            ['period' => '2026-09', 'type' => 'PPN', 'tax_type' => 'SPT Masa PPN 1111', 'sub_date' => '2026-10-28 15:45:00'],
            ['period' => '2026-09', 'type' => 'PPH21', 'tax_type' => 'SPT Masa PPh Pasal 21/26', 'sub_date' => '2026-10-19 11:15:00'],
            ['period' => '2026-10', 'type' => 'PPN', 'tax_type' => 'SPT Masa PPN 1111', 'sub_date' => '2026-11-05 16:20:00'],
            ['period' => '2026-10', 'type' => 'PPH21', 'tax_type' => 'SPT Masa PPh Pasal 21/26', 'sub_date' => '2026-11-06 09:40:00'],
        ];

        foreach ($taxPeriods as $tp) {
            $idempotencyKey = "tax-sub-{$tenantId}-{$tp['type']}-" . str_replace('-', '', $tp['period']);
            $subRef = "DJP-CORETAX-{$tenantId}-{$tp['type']}-" . str_replace('-', '', $tp['period']);

            DB::table('tax_submissions')->updateOrInsert(
                ['tenant_id' => $tenantId, 'idempotency_key' => $idempotencyKey],
                [
                    'company_id' => $tenantId,
                    'provider_name' => 'coretax_pjap',
                    'submission_reference' => $subRef,
                    'invoice_id' => null,
                    'invoice_number' => "SPT-MASA-{$tp['type']}-{$tp['period']}",
                    'tax_invoice_serial_number' => sprintf('010.%03d-26.%08d', $tenantId, mt_rand(10000000, 99999999)),
                    'status' => 'SUCCESS',
                    'raw_payload_hash' => hash('sha256', $idempotencyKey),
                    'request_payload_masked' => json_encode(['period' => $tp['period'], 'tax_type' => $tp['tax_type'], 'tenant_id' => $tenantId]),
                    'response_payload_masked' => json_encode(['status' => 'ACCEPTED', 'bpe_number' => 'BPE-' . strtoupper(Str::random(12)), 'submitted_at' => $tp['sub_date']]),
                    'retry_count' => 0,
                    'submitted_at' => $tp['sub_date'],
                    'created_at' => $tp['sub_date'],
                    'updated_at' => $tp['sub_date'],
                ]
            );
        }

        // 7. Webhook Subscriptions & Webhook Delivery Logs
        $webhook = DB::table('webhook_subscriptions')->where('tenant_id', $tenantId)->first();
        if (! $webhook) {
            $webhookId = DB::table('webhook_subscriptions')->insertGetId([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'store_id' => $storeId,
                'name' => 'Apeiron ERP Integration Bridge Outbound Webhook',
                'target_url' => 'https://erp-bridge.apeiron.internal/api/v1/pos-events',
                'secret_key' => Str::random(64),
                'subscribed_events' => json_encode(['order.completed', 'shift.closed', 'inventory.reconciled', 'journal.posted']),
                'is_active' => 1,
                'failure_count' => 0,
                'last_delivery_at' => '2026-11-10 22:30:00',
                'created_at' => '2026-08-14 08:00:00',
                'updated_at' => '2026-11-10 22:30:00',
            ]);
        } else {
            $webhookId = $webhook->id;
        }

        $logCount = DB::table('webhook_delivery_logs')->where('tenant_id', $tenantId)->where('subscription_id', $webhookId)->count();
        if ($logCount < 12) {
            $events = ['order.completed', 'shift.closed', 'inventory.reconciled', 'journal.posted'];
            $sampleDates = [
                '2026-08-15 12:30:00', '2026-08-25 18:45:00', '2026-09-02 21:10:00',
                '2026-09-12 14:20:00', '2026-09-22 19:35:00', '2026-10-01 22:00:00',
                '2026-10-10 11:15:00', '2026-10-20 16:50:00', '2026-10-30 20:40:00',
                '2026-11-04 13:25:00', '2026-11-08 17:10:00', '2026-11-10 22:30:00',
            ];

            foreach ($sampleDates as $idx => $delivDate) {
                $evt = $events[$idx % count($events)];
                DB::table('webhook_delivery_logs')->insert([
                    'tenant_id' => $tenantId,
                    'subscription_id' => $webhookId,
                    'delivery_id' => 'DLV-' . $tenantId . '-' . str_replace(['-', ' ', ':'], '', $delivDate) . '-' . sprintf('%03d', $idx + 1),
                    'event' => $evt,
                    'payload' => json_encode(['event' => $evt, 'tenant_id' => $tenantId, 'timestamp' => $delivDate]),
                    'response_status' => 200,
                    'response_body' => json_encode(['status' => 'success', 'acknowledged' => true]),
                    'attempt' => 1,
                    'status' => 'delivered',
                    'delivered_at' => $delivDate,
                    'created_at' => $delivDate,
                    'updated_at' => $delivDate,
                ]);
            }
        }
    }

    private function seedHrmAndPayroll(Tenant $tenant, $stores, $employees, int $userId): void
    {
        if (DB::table('shift_templates')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $mainStore = $stores->first();

        // 1. Shift Templates
        $shifts = [
            ['code' => 'PAGI-' . $tenant->id, 'name' => 'Shift Pagi Operasional Bar & Kasir', 'start' => '06:30:00', 'end' => '15:00:00'],
            ['code' => 'MIDDLE-' . $tenant->id, 'name' => 'Shift Middle Peak Hour', 'start' => '10:00:00', 'end' => '18:30:00'],
            ['code' => 'MALAM-' . $tenant->id, 'name' => 'Shift Sore/Malam Closing', 'start' => '14:30:00', 'end' => '23:00:00'],
        ];
        $shiftIds = [];
        foreach ($shifts as $s) {
            $shiftIds[$s['code']] = DB::table('shift_templates')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore->id,
                'shift_code' => $s['code'],
                'name' => $s['name'],
                'start_time' => $s['start'],
                'end_time' => $s['end'],
                'cross_midnight' => 0,
                'unpaid_break_minutes' => 60,
                'grace_period_minutes' => 15,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Salary Components
        $components = [
            ['code' => 'BASIC-' . $tenant->id, 'name' => 'Gaji Pokok Karyawan', 'type' => 'EARNING', 'tax' => 1],
            ['code' => 'TUNJ_JABATAN-' . $tenant->id, 'name' => 'Tunjangan Posisi & Jabatan', 'type' => 'EARNING', 'tax' => 1],
            ['code' => 'TUNJ_MAKAN-' . $tenant->id, 'name' => 'Uang Makan & Transportasi', 'type' => 'EARNING', 'tax' => 0],
            ['code' => 'OVERTIME-' . $tenant->id, 'name' => 'Upah Lembur PP 35/2021', 'type' => 'EARNING', 'tax' => 1],
            ['code' => 'BPJS_TK-' . $tenant->id, 'name' => 'Iuran BPJS Ketenagakerjaan Karyawan', 'type' => 'DEDUCTION', 'tax' => 0],
            ['code' => 'BPJS_KS-' . $tenant->id, 'name' => 'Iuran BPJS Kesehatan Karyawan', 'type' => 'DEDUCTION', 'tax' => 0],
            ['code' => 'PPH21_TER-' . $tenant->id, 'name' => 'Pemotongan PPh 21 TER 2024', 'type' => 'DEDUCTION', 'tax' => 0],
        ];
        $compIds = [];
        foreach ($components as $c) {
            $compIds[$c['code']] = DB::table('salary_components')->insertGetId([
                'tenant_id' => $tenant->id,
                'component_code' => $c['code'],
                'component_name' => $c['name'],
                'type' => $c['type'],
                'is_taxable' => $c['tax'],
                'is_bpjs_kesehatan_subject' => 1,
                'is_bpjs_tk_subject' => 1,
                'is_fixed' => $c['type'] === 'EARNING' ? 1 : 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Leave Policies
        $leavePolicies = [
            ['code' => 'ANNUAL-' . $tenant->id, 'name' => 'Cuti Tahunan Karyawan (12 Hari)', 'days' => 12],
            ['code' => 'SICK-' . $tenant->id, 'name' => 'Cuti Sakit dengan Surat Dokter', 'days' => 14],
            ['code' => 'MARRIAGE-' . $tenant->id, 'name' => 'Cuti Menikah Karyawan', 'days' => 3],
        ];
        $leavePolicyIds = [];
        foreach ($leavePolicies as $lp) {
            $leavePolicyIds[$lp['code']] = DB::table('leave_policies')->insertGetId([
                'tenant_id' => $tenant->id,
                'policy_code' => $lp['code'],
                'policy_name' => $lp['name'],
                'is_paid' => 1,
                'default_days_per_year' => $lp['days'],
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Create 1 base supervisor approval record for HRM operations
        $hrmApprovalId = DB::table('approvals')->insertGetId([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore->id,
            'requester_user_id' => $userId,
            'approver_user_id' => $userId,
            'action_code' => 'HRM_LEAVE_OVERTIME',
            'target_entity_type' => 'EMPLOYEE',
            'target_entity_id' => 1,
            'amount' => 180000.00,
            'reason' => 'Persetujuan lembur & cuti staf operasional',
            'status' => 'APPROVED',
            'consumed_at' => '2026-10-31 19:00:00',
            'created_at' => '2026-10-31 18:00:00',
            'updated_at' => '2026-10-31 19:00:00',
        ]);

        // Process individual employees
        $empList = $employees;
        $firstEmp = $employees->first();
        $firstAttRecordId = null;

        foreach ($empList as $idx => $emp) {
            $store = $stores[$idx % count($stores)];

            // Bank Account
            DB::table('employee_bank_accounts')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'bank_name' => 'Bank Central Asia (BCA)',
                'bank_code' => '014',
                'account_number' => '542019' . str_pad((string) $emp->id, 4, '0', STR_PAD_LEFT),
                'account_holder_name' => $emp->full_name,
                'is_primary' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Branch Assignment
            DB::table('employee_branch_assignments')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'store_id' => $store->id,
                'assignment_type' => 'PRIMARY',
                'valid_from' => '2025-10-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Compensations
            DB::table('employee_compensations')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'employee_id' => $emp->id,
                    'salary_component_id' => $compIds['BASIC-' . $tenant->id],
                    'amount' => 5200000.00,
                    'effective_date' => '2025-10-01',
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'employee_id' => $emp->id,
                    'salary_component_id' => $compIds['TUNJ_MAKAN-' . $tenant->id],
                    'amount' => 800000.00,
                    'effective_date' => '2025-10-01',
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // BPJS Profile
            DB::table('employee_bpjs_profiles')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'bpjs_ketenagakerjaan_number' => '2109' . str_pad((string) $emp->id, 7, '0', STR_PAD_LEFT),
                'bpjs_kesehatan_number' => '000189' . str_pad((string) $emp->id, 7, '0', STR_PAD_LEFT),
                'jkk_risk_grade' => 2,
                'is_bpjs_tk_active' => 1,
                'is_bpjs_ks_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Employment History
            DB::table('employee_employment_histories')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'from_status' => 'PROBATION',
                'to_status' => 'PERMANENT',
                'effective_date' => '2026-01-01',
                'reference_number' => 'SK-TETAP-' . $emp->employee_number,
                'reason' => 'Lulus evaluasi masa percobaan 3 bulan dengan predikat sangat baik',
                'actor_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Leave Balances
            DB::table('leave_balances')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'leave_policy_id' => $leavePolicyIds['ANNUAL-' . $tenant->id],
                'year' => 2026,
                'allocated_days' => 12.00,
                'used_days' => 2.00,
                'remaining_days' => 10.00,
                'expired_days' => 0.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Work Schedule & Attendance Sample
            $schId = DB::table('work_schedules')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'employee_id' => $emp->id,
                'shift_template_id' => $shiftIds['PAGI-' . $tenant->id],
                'schedule_date' => '2026-11-05',
                'status' => 'SCHEDULED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $attId = DB::table('attendance_records')->insertGetId([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'employee_id' => $emp->id,
                'work_schedule_id' => $schId,
                'clock_in_at' => '2026-11-05 06:25:00',
                'clock_out_at' => '2026-11-05 15:05:00',
                'source' => 'BIOMETRIC_POS',
                'status' => 'PRESENT',
                'late_minutes' => 0,
                'early_departure_minutes' => 0,
                'total_break_minutes' => 60,
                'net_work_minutes' => 460,
                'is_locked' => 1,
                'created_at' => '2026-11-05 06:25:00',
                'updated_at' => '2026-11-05 15:05:00',
            ]);

            if ($firstAttRecordId === null) {
                $firstAttRecordId = $attId;
            }

            DB::table('attendance_breaks')->insert([
                'tenant_id' => $tenant->id,
                'attendance_record_id' => $attId,
                'break_start_at' => '2026-11-05 11:30:00',
                'break_end_at' => '2026-11-05 12:30:00',
                'duration_minutes' => 60,
                'created_at' => '2026-11-05 11:30:00',
                'updated_at' => '2026-11-05 12:30:00',
            ]);
        }

        // Leave Request & Transaction Sample
        if ($firstEmp) {
            $lrId = DB::table('leave_requests')->insertGetId([
                'tenant_id' => $tenant->id,
                'employee_id' => $firstEmp->id,
                'leave_policy_id' => $leavePolicyIds['ANNUAL-' . $tenant->id],
                'approval_id' => $hrmApprovalId,
                'start_date' => '2026-08-14',
                'end_date' => '2026-08-15',
                'total_days' => 2.00,
                'reason' => 'Keperluan keluarga di luar kota',
                'status' => 'APPROVED',
                'created_at' => '2026-08-01 09:00:00',
                'updated_at' => '2026-08-02 10:00:00',
            ]);

            DB::table('leave_transactions')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $firstEmp->id,
                'leave_policy_id' => $leavePolicyIds['ANNUAL-' . $tenant->id],
                'transaction_type' => 'USAGE',
                'days_delta' => -2.00,
                'leave_request_id' => $lrId,
                'reason' => 'Penggunaan cuti tahunan disetujui',
                'actor_user_id' => $userId,
                'created_at' => '2026-08-02 10:00:00',
            ]);

            // Overtime record (Linked to attendance_record_id & approval_id)
            if ($firstAttRecordId) {
                DB::table('overtime_records')->insert([
                    'tenant_id' => $tenant->id,
                    'store_id' => $mainStore->id,
                    'employee_id' => $firstEmp->id,
                    'attendance_record_id' => $firstAttRecordId,
                    'approval_id' => $hrmApprovalId,
                    'overtime_date' => '2026-10-31',
                    'day_type' => 'WORKDAY',
                    'start_time' => '2026-10-31 15:00:00',
                    'end_time' => '2026-10-31 18:00:00',
                    'actual_duration_minutes' => 180,
                    'payable_hours' => 3.00,
                    'hourly_rate_basis' => 30000.00,
                    'calculated_amount' => 180000.00,
                    'status' => 'APPROVED',
                    'created_at' => '2026-10-31 15:00:00',
                    'updated_at' => '2026-10-31 19:00:00',
                ]);
            }
        }

        // 4. Payroll Periods & Records across 3 closed months (2026-08, 2026-09, 2026-10)
        $payrollMonths = [
            [
                'code' => '2026-08',
                'name' => 'Payroll Periode Agustus ' . $tenant->id . '-2026',
                'start' => '2026-08-01',
                'end' => '2026-08-31',
                'cutoff' => '2026-08-25',
                'pay_date' => '2026-08-31',
            ],
            [
                'code' => '2026-09',
                'name' => 'Payroll Periode September ' . $tenant->id . '-2026',
                'start' => '2026-09-01',
                'end' => '2026-09-30',
                'cutoff' => '2026-09-25',
                'pay_date' => '2026-09-30',
            ],
            [
                'code' => '2026-10',
                'name' => 'Payroll Periode Oktober ' . $tenant->id . '-2026',
                'start' => '2026-10-01',
                'end' => '2026-10-31',
                'cutoff' => '2026-10-25',
                'pay_date' => '2026-10-31',
            ],
        ];

        $lastBatchId = null;
        $empCount = count($empList);

        foreach ($payrollMonths as $pm) {
            $pPeriodId = DB::table('payroll_periods')->insertGetId([
                'tenant_id' => $tenant->id,
                'period_name' => $pm['name'],
                'start_date' => $pm['start'],
                'end_date' => $pm['end'],
                'cutoff_date' => $pm['cutoff'],
                'payment_date' => $pm['pay_date'],
                'is_closed' => 1,
                'created_at' => $pm['pay_date'] . ' 10:00:00',
                'updated_at' => $pm['pay_date'] . ' 10:00:00',
            ]);

            $batchId = DB::table('payroll_batches')->insertGetId([
                'tenant_id' => $tenant->id,
                'batch_number' => 'PAYBATCH-' . $tenant->id . '-' . str_replace('-', '', $pm['code']),
                'payroll_period_id' => $pPeriodId,
                'period_start_date' => $pm['start'],
                'period_end_date' => $pm['end'],
                'cutoff_date' => $pm['cutoff'],
                'payment_scheduled_date' => $pm['pay_date'],
                'status' => 'FINALIZED',
                'total_employees' => $empCount,
                'total_gross_amount' => $empCount * 10000000.00,
                'total_tax_amount' => $empCount * 250000.00,
                'total_bpjs_employer' => $empCount * 1024000.00,
                'total_bpjs_employee' => $empCount * 300000.00,
                'total_net_amount' => $empCount * 9450000.00,
                'created_by_user_id' => $userId,
                'finalized_at' => $pm['pay_date'] . ' 17:00:00',
                'paid_at' => $pm['pay_date'] . ' 18:00:00',
                'created_at' => $pm['pay_date'] . ' 10:00:00',
                'updated_at' => $pm['pay_date'] . ' 18:00:00',
            ]);
            $lastBatchId = $batchId;

            foreach ($empList as $emp) {
                $prId = DB::table('payroll_records')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'payroll_batch_id' => $batchId,
                    'employee_id' => $emp->id,
                    'employee_number_snapshot' => $emp->employee_number,
                    'full_name_snapshot' => $emp->full_name,
                    'department_name_snapshot' => 'Operasional Toko & Bar',
                    'job_title_snapshot' => 'Store Specialist',
                    'primary_store_id' => $mainStore->id,
                    'primary_store_name_snapshot' => $mainStore->name,
                    'ptkp_status_snapshot' => 'TK/0',
                    'tax_calculation_method_snapshot' => 'TER_2024',
                    'bank_name_snapshot' => 'Bank Central Asia (BCA)',
                    'bank_account_number_snapshot' => '542019' . str_pad((string) $emp->id, 4, '0', STR_PAD_LEFT),
                    'basic_salary_amount' => 7500000.00,
                    'fixed_allowances_amount' => 1500000.00,
                    'variable_allowances_amount' => 0.00,
                    'overtime_amount' => 500000.00,
                    'commissions_amount' => 500000.00,
                    'gross_earnings_amount' => 10000000.00,
                    'taxable_gross_amount' => 10000000.00,
                    'tax_pph21_amount' => 250000.00,
                    'tax_pph21_dtp_amount' => 0.00,
                    'bpjs_ks_employer_amount' => 400000.00,
                    'bpjs_ks_employee_amount' => 75000.00,
                    'bpjs_tk_employer_amount' => 624000.00,
                    'bpjs_tk_employee_amount' => 225000.00,
                    'other_deductions_amount' => 0.00,
                    'net_pay_amount' => 9450000.00,
                    'payslip_token' => Str::random(32),
                    'created_at' => $pm['pay_date'] . ' 10:00:00',
                    'updated_at' => $pm['pay_date'] . ' 18:00:00',
                ]);

                DB::table('payroll_items')->insert([
                    [
                        'tenant_id' => $tenant->id,
                        'payroll_record_id' => $prId,
                        'component_code' => 'BASIC',
                        'component_name' => 'Gaji Pokok',
                        'type' => 'EARNING',
                        'amount' => 7500000.00,
                        'is_taxable' => 1,
                        'created_at' => $pm['pay_date'] . ' 10:00:00',
                        'updated_at' => $pm['pay_date'] . ' 10:00:00',
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'payroll_record_id' => $prId,
                        'component_code' => 'TUNJ_JABATAN',
                        'component_name' => 'Tunjangan Operasional',
                        'type' => 'EARNING',
                        'amount' => 1500000.00,
                        'is_taxable' => 1,
                        'created_at' => $pm['pay_date'] . ' 10:00:00',
                        'updated_at' => $pm['pay_date'] . ' 10:00:00',
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'payroll_record_id' => $prId,
                        'component_code' => 'OVERTIME',
                        'component_name' => 'Lembur PP 35/2021',
                        'type' => 'EARNING',
                        'amount' => 500000.00,
                        'is_taxable' => 1,
                        'created_at' => $pm['pay_date'] . ' 10:00:00',
                        'updated_at' => $pm['pay_date'] . ' 10:00:00',
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'payroll_record_id' => $prId,
                        'component_code' => 'PPH21_TER',
                        'component_name' => 'PPh 21 TER 2024 (2.5%)',
                        'type' => 'DEDUCTION',
                        'amount' => 250000.00,
                        'is_taxable' => 0,
                        'created_at' => $pm['pay_date'] . ' 10:00:00',
                        'updated_at' => $pm['pay_date'] . ' 10:00:00',
                    ],
                ]);
            }
        }

        // 5. Payroll Adjustment
        if ($firstEmp && $lastBatchId) {
            DB::table('payroll_adjustments')->insert([
                'tenant_id' => $tenant->id,
                'employee_id' => $firstEmp->id,
                'payroll_batch_id' => $lastBatchId,
                'adjustment_type' => 'BONUS',
                'amount' => 500000.00,
                'reason' => 'Bonus pencapaian target kepuasan pelanggan outlet (CSAT > 98%)',
                'created_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Commission Rules & Records
        $p1 = DB::table('products')->where('tenant_id', $tenant->id)->first();
        $ruleId = DB::table('commission_rules')->insertGetId([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore->id,
            'rule_name' => 'Insentif Barista Signature Beverages ' . $tenant->id,
            'basis_type' => 'FIXED_PER_ITEM',
            'rate_amount' => 2500.00,
            'product_id' => $p1?->id,
            'is_active' => 1,
            'valid_from' => '2025-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = DB::table('orders')->where('tenant_id', $tenant->id)->first();
        if ($order && $firstEmp) {
            DB::table('commission_records')->insert([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore->id,
                'employee_id' => $firstEmp->id,
                'commission_rule_id' => $ruleId,
                'order_id' => $order->id,
                'calculation_basis_amount' => 75000.00,
                'commission_amount' => 5000.00,
                'type' => 'STANDARD',
                'status' => 'APPROVED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedPromotionsAndOperations(Tenant $tenant, $stores, int $userId): void
    {
        if (DB::table('promotion_campaigns')->where('tenant_id', $tenant->id)->count() > 0) {
            return;
        }

        $mainStore = $stores->first();
        $products = DB::table('products')->where('tenant_id', $tenant->id)->get();
        $p1 = $products->first();
        $p2 = $products->skip(1)->first();

        // 1. Promotion Campaigns (Compliant with JSON constraints)
        $campId = DB::table('promotion_campaigns')->insertGetId([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore->id,
            'campaign_code' => 'PAYDAY-25-' . $tenant->id,
            'name' => 'Promo Payday Festival Diskon 25%',
            'description' => 'Diskon 25% spesial akhir bulan untuk seluruh menu makanan & minuman',
            'type' => 'PERCENTAGE_DISCOUNT',
            'start_datetime' => '2026-10-25 00:00:00',
            'end_datetime' => '2026-11-10 23:59:59',
            'allowed_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            'allowed_channels' => json_encode(['pos', 'web', 'app']),
            'allowed_customer_group_ids' => json_encode([]),
            'priority' => 1,
            'status' => 'ACTIVE',
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($stores as $st) {
            DB::table('promotion_store_mappings')->insert([
                'tenant_id' => $tenant->id,
                'campaign_id' => $campId,
                'store_id' => $st->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ruleId = DB::table('promotion_rules')->insertGetId([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campId,
            'rule_type' => 'MIN_ORDER_AMOUNT',
            'min_order_amount' => 75000.00,
            'trigger_product_ids' => json_encode([]),
            'trigger_category_ids' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('promotion_rewards')->insert([
            'tenant_id' => $tenant->id,
            'rule_id' => $ruleId,
            'reward_type' => 'DISCOUNT_PERCENT',
            'discount_percentage' => 25.00,
            'max_discount_amount' => 50000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('promotion_reservations')->insert([
            'tenant_id' => $tenant->id,
            'store_id' => $mainStore->id,
            'campaign_id' => $campId,
            'cart_uuid' => (string) Str::uuid(),
            'reserved_amount' => 25000.00,
            'status' => 'CONFIRMED',
            'expires_at' => '2026-11-06 23:59:59',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Shelf Labels
        if ($p1) {
            DB::table('shelf_labels')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'store_id' => $mainStore->id,
                    'product_id' => $p1->id,
                    'old_price' => 35000.00,
                    'new_price' => 38000.00,
                    'status' => 'SHELF_LABEL_PRINTED',
                    'batch_number' => 'LBL-BATCH-202611-01',
                    'printed_at' => '2026-11-01 08:30:00',
                    'printed_by_user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'store_id' => $mainStore->id,
                    'product_id' => $p2?->id ?? $p1->id,
                    'old_price' => 28000.00,
                    'new_price' => 30000.00,
                    'status' => 'SHELF_LABEL_PRINTED',
                    'batch_number' => 'LBL-BATCH-202611-01',
                    'printed_at' => '2026-11-01 08:30:00',
                    'printed_by_user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        // 3. Kitchen Waste Logs
        $ing = DB::table('ingredients')->where('tenant_id', $tenant->id)->first();
        if ($ing) {
            DB::table('kitchen_waste_logs')->insert([
                'tenant_id' => $tenant->id,
                'store_id' => $mainStore->id,
                'ingredient_id' => $ing->id,
                'quantity' => 2.5000,
                'cost_price' => 28000.00,
                'total_loss_amount' => 70000.00,
                'reason' => 'SPOILAGE_WASTE',
                'waste_date' => '2026-11-04 22:30:00',
                'logged_by_user_id' => $userId,
                'created_at' => '2026-11-04 22:30:00',
                'updated_at' => '2026-11-04 22:30:00',
            ]);
        }

        // 4. WhatsApp Message Outbox (Compliant with enum: 'pending','processing','sent','failed')
        DB::table('whatsapp_message_outbox')->insert([
            [
                'tenant_id' => $tenant->id,
                'recipient_phone' => '081298765432',
                'message_type' => 'E_RECEIPT',
                'template_id' => 'TMPL_RECEIPT_V1',
                'payload' => json_encode([
                    'customer' => 'Bapak Hendra',
                    'order_number' => 'ORD-202611-00912',
                    'total' => 'Rp 148.000',
                    'pdf_url' => 'https://pos.apeiron.id/receipt/pdf/receipt-00912.pdf',
                ]),
                'status' => 'sent',
                'sent_at' => '2026-11-05 18:45:00',
                'provider_message_id' => 'wamid.HBgMOTEyODk4NzY1NDMyFQIAEhgWM0VFRDI5QjE5' . $tenant->id,
                'created_at' => '2026-11-05 18:44:50',
                'updated_at' => '2026-11-05 18:45:00',
            ],
            [
                'tenant_id' => $tenant->id,
                'recipient_phone' => '081399887766',
                'message_type' => 'PAYSLIP_NOTIFICATION',
                'template_id' => 'TMPL_PAYSLIP_V1',
                'payload' => json_encode([
                    'employee' => 'Rian Lestari',
                    'period' => 'Oktober 2026',
                    'portal_url' => 'https://pos.apeiron.id/employee/payslip/view',
                ]),
                'status' => 'sent',
                'sent_at' => '2025-10-31 18:30:00',
                'provider_message_id' => 'wamid.HBgMOTEzOTk4ODc3NjYFQIAEhgWM0VFRDI5QjE4' . $tenant->id,
                'created_at' => '2025-10-31 18:29:50',
                'updated_at' => '2025-10-31 18:30:00',
            ],
        ]);

        // 5. Vouchers
        $disc = DB::table('discounts')->where('tenant_id', $tenant->id)->first();
        if ($disc && DB::table('vouchers')->where('tenant_id', $tenant->id)->count() === 0) {
            DB::table('vouchers')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'code' => 'VOUCHER-NEWYEAR-' . $tenant->id,
                    'name' => 'Voucher Promo Tahun Baru 2025',
                    'discount_id' => $disc->id,
                    'customer_id' => null,
                    'customer_group_id' => null,
                    'discount_type' => 'FIXED_AMOUNT',
                    'discount_value' => 25000.00,
                    'max_discount_amount' => 25000.00,
                    'min_order_amount' => 100000.00,
                    'max_uses' => 500,
                    'max_uses_per_customer' => 2,
                    'used_count' => 124,
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_until' => '2025-12-31 23:59:59',
                    'status' => 'ACTIVE',
                    'created_at' => '2025-01-01 00:00:00',
                    'updated_at' => '2025-01-01 00:00:00',
                ],
                [
                    'tenant_id' => $tenant->id,
                    'code' => 'VOUCHER-VIP-' . $tenant->id,
                    'name' => 'Voucher Spesial Pelanggan Setia 20%',
                    'discount_id' => $disc->id,
                    'customer_id' => null,
                    'customer_group_id' => null,
                    'discount_type' => 'PERCENTAGE',
                    'discount_value' => 20.00,
                    'max_discount_amount' => 50000.00,
                    'min_order_amount' => 150000.00,
                    'max_uses' => 1000,
                    'max_uses_per_customer' => 5,
                    'used_count' => 312,
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_until' => '2025-12-31 23:59:59',
                    'status' => 'ACTIVE',
                    'created_at' => '2025-01-01 00:00:00',
                    'updated_at' => '2025-01-01 00:00:00',
                ],
            ]);
        }

        // 6. Manufacturing Orders
        $prod = DB::table('products')->where('tenant_id', $tenant->id)->first();
        if ($prod && DB::table('manufacturing_orders')->where('tenant_id', $tenant->id)->count() === 0) {
            DB::table('manufacturing_orders')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'store_id' => $mainStore->id,
                    'order_number' => 'MO-202506-' . sprintf('%03d', $tenant->id) . '-001',
                    'product_id' => $prod->id,
                    'planned_quantity' => 150.0000,
                    'produced_quantity' => 150.0000,
                    'status' => 'COMPLETED',
                    'planned_start_date' => '2025-06-10',
                    'completed_at' => '2025-06-10 16:30:00',
                    'notes' => 'Batch produksi reguler dapur pusat',
                    'created_by' => $userId,
                    'created_at' => '2025-06-10 08:00:00',
                    'updated_at' => '2025-06-10 16:30:00',
                ],
                [
                    'tenant_id' => $tenant->id,
                    'store_id' => $mainStore->id,
                    'order_number' => 'MO-202511-' . sprintf('%03d', $tenant->id) . '-002',
                    'product_id' => $prod->id,
                    'planned_quantity' => 200.0000,
                    'produced_quantity' => 200.0000,
                    'status' => 'COMPLETED',
                    'planned_start_date' => '2025-11-20',
                    'completed_at' => '2025-11-20 17:00:00',
                    'notes' => 'Batch produksi persiapan libur akhir tahun',
                    'created_by' => $userId,
                    'created_at' => '2025-11-20 08:00:00',
                    'updated_at' => '2025-11-20 17:00:00',
                ],
            ]);
        }

        // 7. Payment Inbox (Webhook gateway yang sudah diproses)
        if (DB::table('payment_inbox')->where('tenant_id', $tenant->id)->count() === 0) {
            $recentPayment = DB::table('payments')->where('tenant_id', $tenant->id)->where('payment_method', 'qris')->first();
            $order = $recentPayment ? DB::table('orders')->where('tenant_id', $tenant->id)->where('id', $recentPayment->order_id)->first() : null;
            $orderCode = $order ? $order->order_code : 'ORD-202506-001';
            $payAmount = $recentPayment ? (int) $recentPayment->amount : 75000;

            DB::table('payment_inbox')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'provider' => 'midtrans',
                    'idempotency_key' => 'midtrans-hook-' . $tenant->id . '-20250615-01',
                    'event_type' => 'payment.capture',
                    'payload' => json_encode([
                        'transaction_status' => 'settlement',
                        'order_id' => $orderCode,
                        'gross_amount' => (string) $payAmount,
                        'payment_type' => 'qris',
                        'transaction_time' => '2025-06-15 13:45:00',
                        'fraud_status' => 'accept',
                    ]),
                    'status' => 'processed',
                    'error_message' => null,
                    'processed_at' => '2025-06-15 13:45:05',
                    'created_at' => '2025-06-15 13:45:01',
                    'updated_at' => '2025-06-15 13:45:05',
                ],
                [
                    'tenant_id' => $tenant->id,
                    'provider' => 'midtrans',
                    'idempotency_key' => 'midtrans-hook-' . $tenant->id . '-20251120-02',
                    'event_type' => 'payment.capture',
                    'payload' => json_encode([
                        'transaction_status' => 'settlement',
                        'order_id' => $orderCode . '-2',
                        'gross_amount' => (string) ($payAmount * 2),
                        'payment_type' => 'qris',
                        'transaction_time' => '2025-11-20 19:20:00',
                        'fraud_status' => 'accept',
                    ]),
                    'status' => 'processed',
                    'error_message' => null,
                    'processed_at' => '2025-11-20 19:20:06',
                    'created_at' => '2025-11-20 19:20:01',
                    'updated_at' => '2025-11-20 19:20:06',
                ],
            ]);
        }
    }

    private function seedPartitionedHistoricalLedgers(): void
    {
        // Populate order_items_partitioned from order_items
        $sampleOrderItems = DB::table('order_items')
            ->select('id', 'tenant_id', 'store_id', 'order_id', 'product_id', 'product_variant_id', 'order_price', 'qty', 'order_subtotal', 'item_discount_id as discount_id', 'item_discount_amount as discount_amount', 'notes', 'created_at', 'updated_at')
            ->orderBy('id', 'asc')
            ->limit(1000)
            ->get();

        $rows = [];
        foreach ($sampleOrderItems as $item) {
            $row = (array) $item;
            $row['discount_amount'] = (int) ($row['discount_amount'] ?? 0);
            $rows[] = $row;
        }

        if (!empty($rows)) {
            DB::table('order_items_partitioned')->insertOrIgnore($rows);
        }

        // Populate stock_movements_partitioned from stock_movements
        $sampleMovements = DB::table('stock_movements')
            ->select('id', 'tenant_id', 'store_id', 'product_id', 'type as movement_type', 'quantity', 'reference_type', 'reference_id', 'unit_cost as cost_price', 'reason as notes', 'created_at', 'updated_at')
            ->orderBy('id', 'asc')
            ->limit(1000)
            ->get();

        $mRows = [];
        foreach ($sampleMovements as $mov) {
            $mRows[] = (array) $mov;
        }

        if (!empty($mRows)) {
            DB::table('stock_movements_partitioned')->insertOrIgnore($mRows);
        }
    }

    private function seedPersonalAccessTokens(): void
    {
        if (DB::table('personal_access_tokens')->count() > 0) {
            return;
        }

        $users = DB::table('users')->limit(10)->get();
        foreach ($users as $user) {
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => 'App\Models\User',
                'tokenable_id' => $user->id,
                'name' => 'POS Terminal Tablet #' . $user->id,
                'token' => hash('sha256', 'token_' . $user->id . '_' . Str::random(40)),
                'abilities' => json_encode(['pos:checkout', 'orders:read', 'orders:create']),
                'last_used_at' => '2025-12-30 14:30:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
