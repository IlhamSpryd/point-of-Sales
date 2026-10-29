<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\InvoiceSettlementStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\ApEntryTypeEnum;
use App\Modules\Finance\Enums\ApPaymentAllocationStatusEnum;
use App\Modules\Finance\Enums\ApPaymentStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ApPaymentService;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApPaymentAllocationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $creatorUser;

    protected User $approverUser;

    protected Supplier $supplier;

    protected AccountingPeriod $period;

    protected CashBankAccount $bankAccount;

    protected ApPaymentService $paymentService;

    protected ChartOfAccountService $coaService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        // Seed COA dan Posting Rules
        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->paymentService = app(ApPaymentService::class);
        $this->coaService = app(ChartOfAccountService::class);

        // Buat open accounting period
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

        $nonAdminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Finance Staff'],
            ['is_admin' => false]
        );

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->creatorUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $nonAdminRole->id,
        ]);

        $this->approverUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
        $this->actingAs($this->approverUser);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Kopi Ekspor Indonesia',
            'code' => 'SUP-KEI',
            'is_active' => true,
        ]);

        // Hubungkan CashBankAccount ke GL BANK_MAIN
        $bankGl = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->bankAccount = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-001',
            'account_name' => 'Rekening Operasional BCA',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGl->id,
            'bank_name' => 'Bank Central Asia',
            'bank_account_number' => '1234567890',
            'currency_code' => 'IDR',
            'opening_balance' => '50000000.00',
            'current_balance' => '50000000.00',
            'is_active' => true,
        ]);
    }

    public function test_ap_payment_lifecycle_draft_to_approved_with_sod_enforcement(): void
    {
        // 1. Buat Draft oleh creatorUser
        $payment = $this->paymentService->createDraft([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'cash_bank_account_id' => $this->bankAccount->id,
            'total_payment_amount' => '1000000.00',
            'payment_date' => now()->toDateString(),
        ], $this->creatorUser);

        $this->assertEquals(ApPaymentStatusEnum::DRAFT, $payment->status);
        $this->assertEquals('1000000.00', $payment->total_payment_amount);

        // 2. Ajukan pembayaran
        $this->paymentService->submit($payment, $this->creatorUser);
        $this->assertEquals(ApPaymentStatusEnum::PENDING_APPROVAL, $payment->status);

        // 3. User yang sama (bukan admin) dilarang menyetujui (SoD FIN-M7-10)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIN-M7-10: Pembuat draft pembayaran supplier dilarang menyetujui');
        $this->paymentService->approve($payment, $this->creatorUser);
    }

    public function test_post_payment_allocates_invoice_records_subledger_and_posts_gl_journal(): void
    {
        // Buat Faktur Pemasok
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-TEST-001',
            'supplier_invoice_number' => 'SUPP-INV-1',
            'normalized_supplier_invoice_number' => 'SUPPINV1',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'subtotal_amount' => '2000000.00',
            'total_amount' => '2000000.00',
            'outstanding_amount' => '2000000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'matching_status' => 'matched',
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        // Buat pembayaran Rp 2.000.000
        $payment = $this->paymentService->createDraft([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'cash_bank_account_id' => $this->bankAccount->id,
            'total_payment_amount' => '2000000.00',
            'payment_date' => now()->toDateString(),
        ], $this->approverUser);

        // Post pembayaran dialokasikan penuh ke invoice
        $postedPayment = $this->paymentService->postPayment($payment, [
            [
                'supplier_invoice_id' => $invoice->id,
                'allocated_amount' => '2000000.00',
            ],
        ], $this->approverUser);

        $this->assertEquals(ApPaymentStatusEnum::POSTED, $postedPayment->status);
        $this->assertEquals('2000000.00', $postedPayment->allocated_amount);
        $this->assertEquals('0.00', $postedPayment->unapplied_prepayment_amount);

        // Verifikasi Faktur terupdate
        $invoice->refresh();
        $this->assertEquals('2000000.00', $invoice->paid_amount);
        $this->assertEquals('0.00', $invoice->outstanding_amount);
        $this->assertEquals(InvoiceSettlementStatusEnum::Paid, $invoice->settlement_status);

        // Verifikasi alokasi
        $allocation = $postedPayment->allocations()->first();
        $this->assertNotNull($allocation);
        $this->assertEquals('2000000.00', $allocation->allocated_amount);
        $this->assertEquals(ApPaymentAllocationStatusEnum::POSTED, $allocation->status);

        // Verifikasi subledger mutasi AP (PAYMENT_DEBIT)
        $ledgerEntry = ApLedgerEntry::where('ap_payment_id', $postedPayment->id)->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertEquals(ApEntryTypeEnum::PAYMENT_DEBIT, $ledgerEntry->entry_type);
        $this->assertEquals('2000000.00', $ledgerEntry->debit_amount);
        $this->assertEquals('0.00', $ledgerEntry->credit_amount);

        // Verifikasi mutasi Kas & Bank
        $this->bankAccount->refresh();
        $this->assertEquals('48000000.00', $this->bankAccount->current_balance);

        // Verifikasi GL Jurnal PR-AP-01
        $journal = JournalEntry::find($postedPayment->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('2000000.00', $journal->total_debit);
        $this->assertEquals('2000000.00', $journal->total_credit);

        $lines = $journal->lines()->with('account')->get();
        $apLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AP_CONTROL');
        $bankLine = $lines->first(fn ($l) => $l->account->system_account_key === 'BANK_MAIN');

        $this->assertNotNull($apLine);
        $this->assertNotNull($bankLine);
        $this->assertEquals('2000000.00', $apLine->debit_amount);
        $this->assertEquals('2000000.00', $bankLine->credit_amount);
    }

    public function test_overpayment_prevention_on_invoice_allocation(): void
    {
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-TEST-002',
            'supplier_invoice_number' => 'SUPP-INV-2',
            'normalized_supplier_invoice_number' => 'SUPPINV2',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'subtotal_amount' => '500000.00',
            'total_amount' => '500000.00',
            'outstanding_amount' => '500000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'matching_status' => 'matched',
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        $payment = $this->paymentService->createDraft([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'cash_bank_account_id' => $this->bankAccount->id,
            'total_payment_amount' => '600000.00',
            'payment_date' => now()->toDateString(),
        ], $this->approverUser);

        // Coba alokasikan Rp 600.000 ke invoice yang outstandingnya cuma Rp 500.000
        $this->expectException(ValidationException::class);
        $this->paymentService->postPayment($payment, [
            [
                'supplier_invoice_id' => $invoice->id,
                'allocated_amount' => '600000.00',
            ],
        ], $this->approverUser);
    }

    public function test_void_payment_reverses_invoice_and_gl_journal(): void
    {
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-TEST-003',
            'supplier_invoice_number' => 'SUPP-INV-3',
            'normalized_supplier_invoice_number' => 'SUPPINV3',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'subtotal_amount' => '1000000.00',
            'total_amount' => '1000000.00',
            'outstanding_amount' => '1000000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'matching_status' => 'matched',
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        $payment = $this->paymentService->createDraft([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'cash_bank_account_id' => $this->bankAccount->id,
            'total_payment_amount' => '1000000.00',
            'payment_date' => now()->toDateString(),
        ], $this->approverUser);

        $postedPayment = $this->paymentService->postPayment($payment, [
            [
                'supplier_invoice_id' => $invoice->id,
                'allocated_amount' => '1000000.00',
            ],
        ], $this->approverUser);

        $originalJournalId = $postedPayment->journal_entry_id;

        // Void pembayaran
        $voidedPayment = $this->paymentService->voidPayment($postedPayment, 'Salah rekening transfer', $this->approverUser);

        $this->assertEquals(ApPaymentStatusEnum::VOIDED, $voidedPayment->status);
        $this->assertEquals('Salah rekening transfer', $voidedPayment->void_reason);

        // Faktur kembali unpaid dengan outstanding Rp 1.000.000
        $invoice->refresh();
        $this->assertEquals('0.00', $invoice->paid_amount);
        $this->assertEquals('1000000.00', $invoice->outstanding_amount);
        $this->assertEquals(InvoiceSettlementStatusEnum::Unpaid, $invoice->settlement_status);

        // Alokasi status REVERSED
        $allocation = $voidedPayment->allocations()->first();
        $this->assertEquals(ApPaymentAllocationStatusEnum::REVERSED, $allocation->status);

        // Saldo kas/bank pulih kembali ke Rp 50.000.000
        $this->bankAccount->refresh();
        $this->assertEquals('50000000.00', $this->bankAccount->current_balance);

        // Jurnal GL original berstatus REVERSED
        $origJournal = JournalEntry::find($originalJournalId);
        $this->assertEquals(JournalStatusEnum::REVERSED, $origJournal->status);
    }
}
