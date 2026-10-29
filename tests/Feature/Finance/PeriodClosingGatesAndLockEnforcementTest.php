<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Exceptions\ClosedAccountingPeriodException;
use App\Modules\Finance\Exceptions\ReconciliationGateFailedException;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Modules\Finance\Models\FixedAssetDepreciationSchedule;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PeriodClosingChecklist;
use App\Modules\Finance\Models\PeriodClosingLog;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeriodClosingGatesAndLockEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected AccountingPeriodService $periodService;

    protected JournalEntryService $journalService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

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
    }

    public function test_closing_period_fails_gate_1_if_pending_outbox_events_exist(): void
    {
        // Masukkan outbox berstatus PENDING
        DB::table('financial_event_outboxes')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'event_id' => 'evt_test_outbox_01',
            'event_type' => 'pos.order.paid',
            'source_module' => 'SALES',
            'source_type' => 'ORDER',
            'source_id' => 9999,
            'source_number' => 'ORD-9999',
            'idempotency_key' => 'idemp_outbox_gate1_01',
            'payload' => json_encode(['dummy' => true]),
            'status' => 'PENDING',
            'occurred_at' => now()->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ReconciliationGateFailedException::class);
        $this->expectExceptionMessage('GATE_1_OUTBOX');

        $this->periodService->closePeriod($this->period, $this->user);
    }

    public function test_closing_period_fails_gate_2_if_draft_journals_exist(): void
    {
        // Masukkan journal_entries berstatus DRAFT
        JournalEntry::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'accounting_period_id' => $this->period->id,
            'journal_number' => 'JRN-DRAFT-01',
            'posting_date' => now()->toDateString(),
            'idempotency_key' => 'idemp_draft_gate2_01',
            'journal_type' => JournalTypeEnum::MANUAL_ADJUSTMENT->value,
            'status' => 'DRAFT',
            'total_debit' => '100.00',
            'total_credit' => '100.00',
            'currency_code' => 'IDR',
        ]);

        $this->expectException(ReconciliationGateFailedException::class);
        $this->expectExceptionMessage('GATE_2_DRAFT_JOURNALS');

        $this->periodService->closePeriod($this->period, $this->user);
    }

    public function test_closing_period_fails_gate_4_if_ar_subledger_differs_from_gl_ar(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Pelanggan Uji',
            'email' => 'pelanggan@test.com',
            'phone' => '08123456789',
        ]);

        // Masukkan customer_invoices dengan balance_due > 0 tanpa jurnal GL AR (Akun 1130)
        DB::table('customer_invoices')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'invoice_number' => 'INV-AR-UNMATCHED-01',
            'customer_id' => $customer->id,
            'status' => 'POSTED',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_amount' => 500000.00,
            'balance_due' => 500000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ReconciliationGateFailedException::class);
        $this->expectExceptionMessage('GATE_4_AR_SUBLEDGER');

        $this->periodService->closePeriod($this->period, $this->user);
    }

    public function test_closing_period_fails_gate_5_if_ap_subledger_differs_from_gl_ap(): void
    {
        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Sumber Kopi Maju',
            'code' => 'SUP-001',
            'is_active' => true,
        ]);

        // Masukkan supplier_invoices dengan outstanding_amount > 0 tanpa jurnal GL AP (Akun 2101)
        DB::table('supplier_invoices')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'internal_invoice_number' => 'SINV-INT-001',
            'supplier_invoice_number' => 'SUPP-INV-001',
            'normalized_supplier_invoice_number' => 'SUPPINV001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_amount' => 750000.00,
            'outstanding_amount' => 750000.00,
            'status' => 'approved_payable',
            'matching_status' => 'matched',
            'settlement_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ReconciliationGateFailedException::class);
        $this->expectExceptionMessage('GATE_5_AP_SUBLEDGER');

        $this->periodService->closePeriod($this->period, $this->user);
    }

    public function test_closing_period_fails_gate_7_if_pending_depreciation_schedule_exists(): void
    {
        $cat = FixedAssetCategory::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'TEST_EQ',
            'category_name' => 'Peralatan Uji',
            'depreciation_method' => 'STRAIGHT_LINE',
            'useful_life_months' => 12,
            'asset_gl_account_id' => ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '1510')->value('id'),
            'accum_depr_gl_account_id' => ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '1519')->value('id'),
            'depr_expense_gl_account_id' => ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '6104')->value('id'),
        ]);

        $asset = FixedAsset::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $cat->id,
            'asset_code' => 'AST-GATE7-01',
            'asset_name' => 'Mesin Espresso Gate 7',
            'status' => 'ACTIVE',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '12000000.00',
            'salvage_value' => '0.00',
            'depreciable_base' => '12000000.00',
            'useful_life_months' => 12,
            'accumulated_depreciation' => '0.00',
            'net_book_value' => '12000000.00',
            'depreciation_method' => 'STRAIGHT_LINE',
        ]);

        FixedAssetDepreciationSchedule::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'accounting_period_id' => $this->period->id,
            'fixed_asset_id' => $asset->id,
            'period_year' => (int) now()->year,
            'period_month' => (int) now()->month,
            'depreciation_amount' => '1000000.00',
            'accumulated_depreciation_after' => '1000000.00',
            'net_book_value_after' => '11000000.00',
            'status' => 'PENDING',
        ]);

        $this->expectException(ReconciliationGateFailedException::class);
        $this->expectExceptionMessage('GATE_7_DEPRECIATION');

        $this->periodService->closePeriod($this->period, $this->user);
    }

    public function test_closing_period_succeeds_when_all_7_gates_pass(): void
    {
        // Dalam kondisi bersih (tanpa outbox pending, tanpa draft journal, saldo seimbang 0, subledger seimbang 0, tanpa jadwal pending),
        // seluruh 7 gerbang harus bernilai PASSED.
        $gates = $this->periodService->evaluateClosingGates($this->period, $this->user);

        foreach ($gates as $gateCode => $gate) {
            $this->assertSame('PASSED', $gate['status'], "Gate {$gateCode} harus PASSED pada kondisi bersih.");
        }

        $closed = $this->periodService->closePeriod($this->period, $this->user);

        $this->assertSame(AccountingPeriodStatusEnum::CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($this->user->id, $closed->closed_by_user_id);

        // Verifikasi log tercatat
        $log = PeriodClosingLog::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('action', 'HARD_CLOSE')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(AccountingPeriodStatusEnum::CLOSED->value, $log->new_status);

        // Verifikasi checklists tersimpan di DB
        $checklistCount = PeriodClosingChecklist::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('status', 'PASSED')
            ->count();
        $this->assertSame(7, $checklistCount);
    }

    public function test_closed_and_locked_periods_reject_new_journal_postings(): void
    {
        $this->periodService->closePeriod($this->period, $this->user);

        $cashAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '1101')->first();
        $revenueAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '4101')->first();

        $lines = [
            new JournalLineDto(accountId: $cashAccount->id, debitAmount: '1000.00', creditAmount: '0.00'),
            new JournalLineDto(accountId: $revenueAccount->id, debitAmount: '0.00', creditAmount: '1000.00'),
        ];

        $this->expectException(ClosedAccountingPeriodException::class);
        $this->expectExceptionMessage('CLOSED');

        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $this->period->start_date,
            idempotencyKey: 'idemp_post_to_closed_period',
            lines: $lines,
            notes: 'Attempt posting to closed period'
        );
    }
}
