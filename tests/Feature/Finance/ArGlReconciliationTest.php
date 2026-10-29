<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Services\ArGlReconciliationService;
use App\Modules\Finance\Services\ArOutboxConsumerService;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArGlReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected Customer $customer;

    protected CustomerCreditAccount $creditAccount;

    protected AccountingPeriod $period;

    protected CashBankAccount $bankAccount;

    protected AccountsReceivableService $arService;

    protected ArOutboxConsumerService $arOutboxConsumer;

    protected ArGlReconciliationService $reconService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->arService = app(AccountsReceivableService::class);
        $this->arOutboxConsumer = app(ArOutboxConsumerService::class);
        $this->reconService = app(ArGlReconciliationService::class);

        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('n'),
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $ownerRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $ownerRole->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Mitra Distribusi Utama',
            'email' => 'mitra@distribusi.com',
            'phone' => '081234567899',
            'status' => 'active',
        ]);

        $this->creditAccount = CustomerCreditAccount::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'credit_limit' => '100000000.00',
            'reserved_exposure' => '0.00',
            'invoiced_exposure' => '0.00',
            'unapplied_credit_balance' => '0.00',
            'status' => 'active',
        ]);

        $bankGl = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('system_account_key', 'BANK_MAIN')
            ->firstOrFail();

        $this->bankAccount = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-REC-001',
            'account_name' => 'BCA Rekonsiliasi AR',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGl->id,
            'bank_name' => 'Bank Central Asia',
            'bank_account_number' => '555666777',
            'currency_code' => 'IDR',
            'opening_balance' => '10000000.00',
            'current_balance' => '10000000.00',
            'is_active' => true,
        ]);
    }

    public function test_ar_aging_schedule_buckets_correctly(): void
    {
        $today = now();

        // 1. Invoice Current (belum jatuh tempo) Rp 1.000.000
        CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-AGE-CUR',
            'invoice_date' => $today->toDateString(),
            'due_date' => $today->copy()->addDays(10)->toDateString(),
            'subtotal' => '1000000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '1000000.00',
            'paid_amount' => '0.00',
            'balance_due' => '1000000.00',
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'created_by_user_id' => $this->user->id,
        ]);

        // 2. Invoice Overdue 1-30 days Rp 2.000.000
        CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-AGE-30',
            'invoice_date' => $today->copy()->subDays(40)->toDateString(),
            'due_date' => $today->copy()->subDays(15)->toDateString(),
            'subtotal' => '2000000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '2000000.00',
            'paid_amount' => '0.00',
            'balance_due' => '2000000.00',
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'created_by_user_id' => $this->user->id,
        ]);

        // 3. Invoice Overdue 31-60 days Rp 3.000.000
        CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-AGE-60',
            'invoice_date' => $today->copy()->subDays(75)->toDateString(),
            'due_date' => $today->copy()->subDays(45)->toDateString(),
            'subtotal' => '3000000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '3000000.00',
            'paid_amount' => '0.00',
            'balance_due' => '3000000.00',
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'created_by_user_id' => $this->user->id,
        ]);

        $aging = $this->reconService->getArAgingSchedule($this->tenant->id, $today->toDateString());

        $this->assertEquals(3, $aging['invoices_count']);
        $this->assertEquals('1000000.00', $aging['current']);
        $this->assertEquals('2000000.00', $aging['1_30']);
        $this->assertEquals('3000000.00', $aging['31_60']);
        $this->assertEquals('0.00', $aging['61_90']);
        $this->assertEquals('0.00', $aging['over_90']);
        $this->assertEquals('6000000.00', $aging['total_outstanding']);
    }

    public function test_ar_to_gl_4_way_reconciliation_verifies_balance_equilibrium(): void
    {
        // 1. Terbitkan 2 faktur
        $inv1 = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-REC-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '2000000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '2000000.00',
            'paid_amount' => '0.00',
            'balance_due' => '2000000.00',
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'created_by_user_id' => $this->user->id,
        ]);

        $inv2 = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-REC-002',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '3000000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '3000000.00',
            'paid_amount' => '0.00',
            'balance_due' => '3000000.00',
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->arService->postInvoice($inv1, $this->user);
        $this->arService->postInvoice($inv2, $this->user);

        // Konsumsi outbox invoice
        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        // Rekonsiliasi sebelum pembayaran (Total Rp 5.000.000)
        $reconBefore = $this->reconService->reconcileArToGl($this->tenant->id);
        $this->assertTrue($reconBefore['is_reconciled']);
        $this->assertEquals('5000000.00', $reconBefore['customer_invoices_outstanding']);
        $this->assertEquals('5000000.00', $reconBefore['ar_subledger_balance']);
        $this->assertEquals('5000000.00', $reconBefore['customer_invoiced_exposure']);
        $this->assertEquals('5000000.00', $reconBefore['gl_ar_control_balance']);
        $this->assertEquals('0.00', $reconBefore['diff_subledger_vs_invoices']);
        $this->assertEquals('0.00', $reconBefore['diff_subledger_vs_gl']);
        $this->assertEquals('0.00', $reconBefore['diff_invoices_vs_gl']);

        // 2. Lakukan pembayaran parsial Rp 1.500.000 ke Inv1
        $this->arService->recordAndAllocatePayment(
            $this->customer->id,
            [
                'amount' => '1500000.00',
                'payment_reference' => 'TRF-REC-PARTIAL',
                'cash_bank_account_id' => $this->bankAccount->id,
            ],
            [
                [
                    'invoice_id' => $inv1->id,
                    'amount' => '1500000.00',
                ],
            ],
            $this->user
        );

        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        // Rekonsiliasi setelah pembayaran parsial (Sisa Rp 3.500.000)
        $reconAfter = $this->reconService->reconcileArToGl($this->tenant->id);
        $this->assertTrue($reconAfter['is_reconciled']);
        $this->assertEquals('3500000.00', $reconAfter['customer_invoices_outstanding']);
        $this->assertEquals('3500000.00', $reconAfter['ar_subledger_balance']);
        $this->assertEquals('3500000.00', $reconAfter['customer_invoiced_exposure']);
        $this->assertEquals('3500000.00', $reconAfter['gl_ar_control_balance']);
        $this->assertEquals('0.00', $reconAfter['diff_subledger_vs_invoices']);
        $this->assertEquals('0.00', $reconAfter['diff_subledger_vs_gl']);
        $this->assertEquals('0.00', $reconAfter['diff_invoices_vs_gl']);
    }
}
