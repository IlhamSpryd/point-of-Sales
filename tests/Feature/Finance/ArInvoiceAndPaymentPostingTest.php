<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ArOutboxConsumerService;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\AccountingReceivableOutbox;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArInvoiceAndPaymentPostingTest extends TestCase
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

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        // Seed COA dan Posting Rules
        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->arService = app(AccountsReceivableService::class);
        $this->arOutboxConsumer = app(ArOutboxConsumerService::class);

        // Buat open accounting period
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
            'name' => 'PT Pelanggan Sejahtera B2B',
            'email' => 'b2b@pelanggan.com',
            'phone' => '081234567890',
            'status' => 'active',
        ]);

        $this->creditAccount = CustomerCreditAccount::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'credit_limit' => '50000000.00',
            'reserved_exposure' => '0.00',
            'invoiced_exposure' => '0.00',
            'unapplied_credit_balance' => '0.00',
            'status' => 'active',
        ]);

        // Rekening Bank
        $bankGl = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('system_account_key', 'BANK_MAIN')
            ->firstOrFail();

        $this->bankAccount = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-AR-001',
            'account_name' => 'BCA Operasional AR',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGl->id,
            'bank_name' => 'Bank Central Asia',
            'bank_account_number' => '888999111',
            'currency_code' => 'IDR',
            'opening_balance' => '10000000.00',
            'current_balance' => '10000000.00',
            'is_active' => true,
        ]);
    }

    public function test_b2b_customer_invoice_posting_emits_outbox_and_posts_gl_journal(): void
    {
        // 1. Buat Draft Invoice
        $invoice = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TEST-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '2000000.00',
            'tax_amount' => '220000.00',
            'discount_amount' => '100000.00',
            'shipping_amount' => '50000.00',
            'total_amount' => '2170000.00',
            'paid_amount' => '0.00',
            'balance_due' => '2170000.00',
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'created_by_user_id' => $this->user->id,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'Paket B2B Konsultasi',
            'standard_cost' => 1000000,
            'product_price' => 2000000,
            'stock' => 0,
        ]);

        $invoice->lines()->create([
            'tenant_id' => $this->tenant->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'description' => 'Paket B2B Konsultasi',
            'quantity' => '1.0000',
            'unit_price' => '2000000.00',
            'subtotal' => '2000000.00',
            'tax_amount' => '220000.00',
            'discount_amount' => '100000.00',
            'line_total' => '2120000.00',
        ]);

        // 2. Post Invoice via AccountsReceivableService
        $postedInvoice = $this->arService->postInvoice($invoice, $this->user);
        $this->assertEquals(CustomerInvoiceStatusEnum::POSTED, $postedInvoice->status);

        // 3. Verifikasi outbox
        $outbox = AccountingReceivableOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'INVOICE_POSTED')
            ->where('source_document_id', $postedInvoice->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('pending', $outbox->status);

        // 4. Konsumsi outbox via ArOutboxConsumerService
        $processed = $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);
        $this->assertEquals(1, $processed);

        $outbox->refresh();
        $this->assertEquals('processed_by_m7', $outbox->status);

        // 5. Verifikasi Jurnal GL PR-AR-01
        $postedInvoice->refresh();
        $this->assertNotNull($postedInvoice->gl_journal_entry_id);

        $journal = JournalEntry::find($postedInvoice->gl_journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        // Debit: AR_CONTROL (2.170.000) + SALES_DISCOUNT (100.000) = 2.270.000
        // Credit: SALES_REVENUE_B2B (2.000.000) + SHIPPING_REVENUE (50.000) + TAX_OUTPUT_PPN (220.000) = 2.270.000
        $this->assertEquals('2270000.00', $journal->total_debit);
        $this->assertEquals('2270000.00', $journal->total_credit);

        $lines = $journal->lines()->with('account')->get();
        $arLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AR_CONTROL');
        $revLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SALES_REVENUE_B2B');
        $taxLine = $lines->first(fn ($l) => $l->account->system_account_key === 'TAX_OUTPUT_PPN');
        $discLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SALES_DISCOUNT');
        $shipLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SHIPPING_REVENUE');

        $this->assertNotNull($arLine);
        $this->assertEquals('2170000.00', $arLine->debit_amount);

        $this->assertNotNull($revLine);
        $this->assertEquals('2000000.00', $revLine->credit_amount);

        $this->assertNotNull($taxLine);
        $this->assertEquals('220000.00', $taxLine->credit_amount);

        $this->assertNotNull($discLine);
        $this->assertEquals('100000.00', $discLine->debit_amount);

        $this->assertNotNull($shipLine);
        $this->assertEquals('50000.00', $shipLine->credit_amount);
    }

    public function test_customer_payment_allocation_emits_outbox_and_posts_gl_journal_with_cash_bank_mutation(): void
    {
        // 1. Buat dan Post Invoice Rp 1.500.000
        $invoice = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TEST-002',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '1500000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '1500000.00',
            'paid_amount' => '0.00',
            'balance_due' => '1500000.00',
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->arService->postInvoice($invoice, $this->user);
        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        // 2. Alokasikan pembayaran penuh Rp 1.500.000 via Rekening Bank
        $result = $this->arService->recordAndAllocatePayment(
            $this->customer->id,
            [
                'amount' => '1500000.00',
                'payment_reference' => 'TRF-BCA-001',
                'cash_bank_account_id' => $this->bankAccount->id,
                'payment_method' => 'bank_transfer',
            ],
            [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => '1500000.00',
                ],
            ],
            $this->user
        );

        $this->assertEquals('1500000.00', $result['total_allocated']);
        $this->assertEquals('0.00', $result['unapplied_credit']);
        $this->assertNotNull($result['receipt']);

        $receipt = $result['receipt'];
        $this->assertEquals('1500000.00', $receipt->total_received_amount);

        // 3. Konsumsi outbox pembayaran
        $processed = $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);
        $this->assertGreaterThanOrEqual(1, $processed);

        // 4. Verifikasi mutasi Kas & Bank
        $this->bankAccount->refresh();
        $this->assertEquals('11500000.00', $this->bankAccount->current_balance);

        $cbt = CashBankTransaction::where('cash_bank_account_id', $this->bankAccount->id)
            ->where('reference_number', $receipt->receipt_number)
            ->first();

        $this->assertNotNull($cbt);
        $this->assertEquals(CashBankTransactionTypeEnum::RECEIPT, $cbt->transaction_type);
        $this->assertEquals('1500000.00', $cbt->amount);

        // 5. Verifikasi Jurnal GL PR-AR-03
        $receipt->refresh();
        $this->assertNotNull($receipt->journal_entry_id);

        $journal = JournalEntry::find($receipt->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('1500000.00', $journal->total_debit);
        $this->assertEquals('1500000.00', $journal->total_credit);

        // Debit: BANK_MAIN (1.500.000), Credit: AR_CONTROL (1.500.000)
        $lines = $journal->lines()->with('account')->get();
        $bankLine = $lines->first(fn ($l) => $l->account->system_account_key === 'BANK_MAIN');
        $arLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AR_CONTROL');

        $this->assertNotNull($bankLine);
        $this->assertEquals('1500000.00', $bankLine->debit_amount);

        $this->assertNotNull($arLine);
        $this->assertEquals('1500000.00', $arLine->credit_amount);
    }

    public function test_invoice_void_reverses_gl_journal(): void
    {
        // 1. Buat dan Post Invoice
        $invoice = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TEST-003',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '500000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total_amount' => '500000.00',
            'paid_amount' => '0.00',
            'balance_due' => '500000.00',
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->arService->postInvoice($invoice, $this->user);
        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        $invoice->refresh();
        $originalJournalId = $invoice->gl_journal_entry_id;
        $this->assertNotNull($originalJournalId);

        // 2. Void Invoice
        $voidedInvoice = $this->arService->voidInvoice($invoice, $this->user, 'Salah pesanan pelanggan');
        $this->assertEquals(CustomerInvoiceStatusEnum::VOIDED, $voidedInvoice->status);

        // 3. Konsumsi outbox void
        $this->arOutboxConsumer->processPendingOutbox($this->tenant->id);

        // 4. Verifikasi jurnal asli dibalik menjadi REVERSED
        $origJournal = JournalEntry::find($originalJournalId);
        $this->assertEquals(JournalStatusEnum::REVERSED, $origJournal->status);
    }
}
