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
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ArGlReconciliationService;
use App\Modules\Finance\Services\ArOutboxConsumerService;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Modules\Sales\Services\ReturnRefundService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArOverpaymentAndCreditNoteAccountingTest extends TestCase
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

    protected ReturnRefundService $returnService;

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
        $this->returnService = app(ReturnRefundService::class);
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
            'name' => 'PT Pelanggan Retail & Grosir',
            'email' => 'grosir@pelanggan.com',
            'phone' => '081234567891',
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
            'account_code' => 'MND-AR-001',
            'account_name' => 'Mandiri Operasional AR',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGl->id,
            'bank_name' => 'Bank Mandiri',
            'bank_account_number' => '123000456',
            'currency_code' => 'IDR',
            'opening_balance' => '20000000.00',
            'current_balance' => '20000000.00',
            'is_active' => true,
        ]);
    }

    public function test_customer_overpayment_posts_unapplied_credit_liability_and_reconciles(): void
    {
        // 1. Buat dan Post Invoice Rp 1.000.000
        $invoice = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-OVER-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '1000000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '1000000.00',
            'paid_amount' => '0.00',
            'balance_due' => '1000000.00',
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->arService->postInvoice($invoice, $this->user);
        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        // 2. Pelanggan membayar Rp 1.500.000 (Kelebihan Bayar Rp 500.000)
        $result = $this->arService->recordAndAllocatePayment(
            $this->customer->id,
            [
                'amount' => '1500000.00',
                'payment_reference' => 'TRF-OVER-001',
                'cash_bank_account_id' => $this->bankAccount->id,
                'payment_method' => 'bank_transfer',
            ],
            [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => '1000000.00',
                ],
            ],
            $this->user
        );

        $this->assertEquals('1000000.00', $result['total_allocated']);
        $this->assertEquals('500000.00', $result['unapplied_credit']);

        $receipt = $result['receipt'];
        $this->assertEquals('1500000.00', $receipt->total_received_amount);
        $this->assertEquals('1000000.00', $receipt->total_allocated_amount);
        $this->assertEquals('500000.00', $receipt->unapplied_credit_amount);

        // 3. Konsumsi outbox
        $processed = $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);
        $this->assertGreaterThanOrEqual(1, $processed);

        // 4. Verifikasi Jurnal GL PR-AR-03
        $receipt->refresh();
        $this->assertNotNull($receipt->journal_entry_id);

        $journal = JournalEntry::find($receipt->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        // Total Debit = Total Credit = Rp 1.500.000
        $this->assertEquals('1500000.00', $journal->total_debit);
        $this->assertEquals('1500000.00', $journal->total_credit);

        $lines = $journal->lines()->with('account')->get();
        $bankLine = $lines->first(fn ($l) => $l->account->system_account_key === 'BANK_MAIN');
        $arLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AR_CONTROL');
        $overpayLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CUSTOMER_UNAPPLIED_CREDIT_LIABILITY');

        $this->assertNotNull($bankLine);
        $this->assertEquals('1500000.00', $bankLine->debit_amount);

        $this->assertNotNull($arLine);
        $this->assertEquals('1000000.00', $arLine->credit_amount);

        $this->assertNotNull($overpayLine);
        $this->assertEquals('500000.00', $overpayLine->credit_amount);

        // 5. Rekonsiliasi Kelebihan Bayar
        $recon = $this->reconService->reconcileCustomerUnappliedCredits($this->tenant->id);
        $this->assertTrue($recon['is_reconciled']);
        $this->assertEquals('500000.00', $recon['operational_unapplied_credits']);
        $this->assertEquals('500000.00', $recon['gl_unapplied_credits_liability']);
        $this->assertEquals('0.00', $recon['difference']);
    }

    public function test_sales_credit_note_applied_posts_sales_return_and_reduces_ar_control(): void
    {
        // 1. Buat dan Post Invoice Rp 2.000.000
        $invoice = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-CN-001',
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

        $this->arService->postInvoice($invoice, $this->user);
        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        // 2. Buat SalesCreditNote Rp 400.000
        $creditNote = SalesCreditNote::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'credit_note_number' => 'CN-TEST-001',
            'issue_date' => now()->toDateString(),
            'subtotal' => '400000.00',
            'tax_amount' => '0.00',
            'total_amount' => '400000.00',
            'remaining_amount' => '400000.00',
            'status' => 'ISSUED',
            'reason_code' => 'DAMAGE',
            'issued_by_user_id' => $this->user->id,
            'issued_at' => now(),
        ]);

        // 3. Aplikasikan Nota Kredit ke Faktur via ReturnRefundService
        $this->returnService->applyCreditNoteToInvoice(
            $creditNote,
            $invoice,
            '400000.00',
            $this->user->id
        );

        // 4. Konsumsi outbox CREDIT_NOTE_APPLIED
        $processed = $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);
        $this->assertGreaterThanOrEqual(1, $processed);

        // 5. Verifikasi Jurnal GL PR-AR-04
        $journal = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'sales_credit_note')
            ->where('source_id', $creditNote->id)
            ->first();

        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('400000.00', $journal->total_debit);
        $this->assertEquals('400000.00', $journal->total_credit);

        // Debit: SALES_RETURN (400.000), Credit: AR_CONTROL (400.000)
        $lines = $journal->lines()->with('account')->get();
        $retLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SALES_RETURN');
        $arLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AR_CONTROL');

        $this->assertNotNull($retLine);
        $this->assertEquals('400000.00', $retLine->debit_amount);

        $this->assertNotNull($arLine);
        $this->assertEquals('400000.00', $arLine->credit_amount);
    }
}
