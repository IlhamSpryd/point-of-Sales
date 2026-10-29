<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\ExpenseStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\ExpenseService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpensePaymentFailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $submitter;

    protected User $approver;

    protected AccountingPeriod $period;

    protected ExpenseService $expenseService;

    protected ChartOfAccountService $coaService;

    protected ExpenseCategory $category;

    protected CashBankAccount $insufficientBank;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->expenseService = app(ExpenseService::class);
        $this->coaService = app(ChartOfAccountService::class);

        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('m'),
            'status' => AccountingPeriodStatusEnum::OPEN,
            'is_reconciled' => false,
        ]);

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->submitter = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        $this->approver = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        $utilAccount = $this->coaService->getAccountBySystemKey('EXPENSE_UTILITIES', $this->tenant->id);
        $this->category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'UTIL',
            'category_name' => 'Biaya Utilitas Toko',
            'expense_gl_account_id' => $utilAccount->id,
            'is_active' => true,
        ]);

        $bankGlAccount = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->insufficientBank = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'PETTY-001',
            'account_name' => 'Kas Kecil Kasir',
            'account_type' => CashBankAccountTypeEnum::PETTY_CASH,
            'gl_account_id' => $bankGlAccount->id,
            'currency_code' => 'IDR',
            'opening_balance' => '200000.00',
            'current_balance' => '200000.00',
            'is_active' => true,
        ]);
    }

    public function test_insufficient_balance_aborts_payment_without_gl_corruption(): void
    {
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'expense_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Beban Perbaikan Genset', 'amount' => '1000000.00'],
            ],
        ], $this->submitter);

        $this->expenseService->submit($expense, $this->submitter);
        $this->expenseService->approve($expense, $this->approver);

        $journalCountBefore = JournalEntry::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();

        // Coba bayar 1,000,000.00 dari kas kecil yang hanya bersaldo 200,000.00
        $this->actingAs($this->approver);
        try {
            $this->expenseService->recordPayment(
                $expense,
                $this->insufficientBank,
                now()->toDateString(),
                $this->approver
            );
            $this->fail('Harus gagal karena saldo kas tidak mencukupi.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('FIN-M7-05', $e->getMessage());
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        // Pastikan saldo tidak berkurang
        $this->insufficientBank->refresh();
        $this->assertEquals('200000.00', (string) $this->insufficientBank->current_balance);

        // Pastikan status beban tetap APPROVED dan payment_journal_id tetap null
        $expense->refresh();
        $this->assertEquals(ExpenseStatusEnum::APPROVED, $expense->status);
        $this->assertNull($expense->payment_journal_id);
        $this->assertNull($expense->cash_bank_transaction_id);
        $this->assertNull($expense->paid_at);

        // Pastikan tidak ada jurnal yatim di GL
        $journalCountAfter = JournalEntry::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
        $this->assertEquals($journalCountBefore, $journalCountAfter);
    }

    public function test_payment_with_cross_tenant_bank_account_is_blocked(): void
    {
        // Buat tenant lain
        $tenant2 = Tenant::create(['name' => 'Tenant Lain']);
        app(TenantContext::class)->setTenantId($tenant2->id);
        app(ChartOfAccountsSeeder::class)->seedForTenant($tenant2->id);

        $otherBankGl = $this->coaService->getAccountBySystemKey('BANK_MAIN', $tenant2->id);
        $otherBank = CashBankAccount::create([
            'tenant_id' => $tenant2->id,
            'account_code' => 'OTHER-BANK',
            'account_name' => 'Bank Tenant Lain',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $otherBankGl->id,
            'currency_code' => 'IDR',
            'opening_balance' => '10000000.00',
            'current_balance' => '10000000.00',
            'is_active' => true,
        ]);

        // Kembalikan ke tenant 1
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'expense_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Beban Kantor', 'amount' => '500000.00'],
            ],
        ], $this->submitter);

        $this->expenseService->submit($expense, $this->submitter);
        $this->expenseService->approve($expense, $this->approver);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Akun kas/bank tidak berada dalam tenant yang sama');

        $this->expenseService->recordPayment($expense, $otherBank, now()->toDateString(), $this->approver);
    }
}
