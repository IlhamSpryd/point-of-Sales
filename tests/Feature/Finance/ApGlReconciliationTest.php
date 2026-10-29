<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\InvoiceSettlementStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Services\ApGlReconciliationService;
use App\Modules\Finance\Services\ApOutboxConsumerService;
use App\Modules\Finance\Services\ApPaymentService;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApGlReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected Supplier $supplier;

    protected Product $product;

    protected AccountingPeriod $period;

    protected CashBankAccount $bankAccount;

    protected PurchaseOrderService $poService;

    protected GoodsReceivedNoteService $grnService;

    protected SupplierInvoiceMatchingService $invoiceMatchingService;

    protected ApOutboxConsumerService $apOutboxConsumer;

    protected ApPaymentService $paymentService;

    protected ApGlReconciliationService $reconciliationService;

    protected ChartOfAccountService $coaService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        // Seed COA dan Posting Rules
        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->invoiceMatchingService = app(SupplierInvoiceMatchingService::class);
        $this->apOutboxConsumer = app(ApOutboxConsumerService::class);
        $this->paymentService = app(ApPaymentService::class);
        $this->reconciliationService = app(ApGlReconciliationService::class);
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

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
        $this->actingAs($this->user);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'CV Mandiri Sejahtera',
            'code' => 'SUP-MND',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'standard_cost' => 20000.00,
            'stock' => 0,
        ]);

        $bankGl = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->bankAccount = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'MANDIRI-01',
            'account_name' => 'Rekening Bank Mandiri',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGl->id,
            'opening_balance' => '100000000.00',
            'current_balance' => '100000000.00',
            'is_active' => true,
        ]);
    }

    public function test_ap_aging_schedule_categorizes_overdue_invoices_correctly(): void
    {
        $asOf = now()->startOfDay();

        // 1. Current (jatuh tempo 10 hari lagi)
        SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-CUR-01',
            'supplier_invoice_number' => 'SUP-C01',
            'normalized_supplier_invoice_number' => 'SUPC01',
            'invoice_date' => $asOf->toDateString(),
            'due_date' => $asOf->copy()->addDays(10)->toDateString(),
            'total_amount' => '100000.00',
            'outstanding_amount' => '100000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        // 2. 1-30 hari overdue (jatuh tempo 15 hari lalu)
        SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-OVD-15',
            'supplier_invoice_number' => 'SUP-O15',
            'normalized_supplier_invoice_number' => 'SUPO15',
            'invoice_date' => $asOf->copy()->subDays(45)->toDateString(),
            'due_date' => $asOf->copy()->subDays(15)->toDateString(),
            'total_amount' => '200000.00',
            'outstanding_amount' => '200000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        // 3. 31-60 hari overdue (jatuh tempo 45 hari lalu)
        SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-OVD-45',
            'supplier_invoice_number' => 'SUP-O45',
            'normalized_supplier_invoice_number' => 'SUPO45',
            'invoice_date' => $asOf->copy()->subDays(75)->toDateString(),
            'due_date' => $asOf->copy()->subDays(45)->toDateString(),
            'total_amount' => '300000.00',
            'outstanding_amount' => '300000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        // 4. 61-90 hari overdue (jatuh tempo 75 hari lalu)
        SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-OVD-75',
            'supplier_invoice_number' => 'SUP-O75',
            'normalized_supplier_invoice_number' => 'SUPO75',
            'invoice_date' => $asOf->copy()->subDays(105)->toDateString(),
            'due_date' => $asOf->copy()->subDays(75)->toDateString(),
            'total_amount' => '400000.00',
            'outstanding_amount' => '400000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        // 5. Over 90 hari overdue (jatuh tempo 120 hari lalu)
        SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-OVD-120',
            'supplier_invoice_number' => 'SUP-O120',
            'normalized_supplier_invoice_number' => 'SUPO120',
            'invoice_date' => $asOf->copy()->subDays(150)->toDateString(),
            'due_date' => $asOf->copy()->subDays(120)->toDateString(),
            'total_amount' => '500000.00',
            'outstanding_amount' => '500000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        $report = $this->reconciliationService->getAgingReport($this->tenant->id, null, $asOf->toDateString());

        $this->assertEquals('100000.00', $report['totals']['current']);
        $this->assertEquals('200000.00', $report['totals']['1_30_days']);
        $this->assertEquals('300000.00', $report['totals']['31_60_days']);
        $this->assertEquals('400000.00', $report['totals']['61_90_days']);
        $this->assertEquals('500000.00', $report['totals']['over_90_days']);
        $this->assertEquals('1500000.00', $report['totals']['grand_total']);
    }

    public function test_ap_to_gl_reconciliation_verifies_balance_equilibrium(): void
    {
        // 1. Buat Faktur Pemasok Rp 1.000.000
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'internal_invoice_number' => 'INV-RECON-001',
            'supplier_invoice_number' => 'SUP-REC-1',
            'normalized_supplier_invoice_number' => 'SUPPREC1',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal_amount' => '1000000.00',
            'total_amount' => '1000000.00',
            'outstanding_amount' => '1000000.00',
            'status' => SupplierInvoiceStatusEnum::ApprovedPayable,
            'matching_status' => 'matched',
            'settlement_status' => InvoiceSettlementStatusEnum::Unpaid,
        ]);

        $invLine = $invoice->lines()->create([
            'tenant_id' => $this->tenant->id,
            'line_number' => 1,
            'purchasable_type' => 'product',
            'purchasable_id' => $this->product->id,
            'invoiced_qty' => '50.0000',
            'unit_price' => '20000.0000',
            'line_total' => '1000000.00',
        ]);

        // Simulasikan outbox approved_payable
        AccountingPayableOutbox::create([
            'tenant_id' => $this->tenant->id,
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'purchasing.invoice.approved_payable',
            'idempotency_key' => "inv:recon:{$invoice->id}",
            'source_document_type' => 'supplier_invoice',
            'source_document_id' => $invoice->id,
            'source_document_number' => $invoice->internal_invoice_number,
            'status' => 'pending',
            'payload' => [],
        ]);

        $this->apOutboxConsumer->processPendingOutbox($this->tenant->id);

        // Periksa rekonsiliasi sebelum pembayaran
        $reconBefore = $this->reconciliationService->reconcileApToGl($this->tenant->id);
        $this->assertTrue($reconBefore['is_reconciled']);
        $this->assertEquals('1000000.00', $reconBefore['supplier_invoices_outstanding']);
        $this->assertEquals('1000000.00', $reconBefore['ap_subledger_balance']);
        $this->assertEquals('1000000.00', $reconBefore['gl_ap_control_balance']);
        $this->assertEquals('0.00', $reconBefore['diff_subledger_vs_invoices']);
        $this->assertEquals('0.00', $reconBefore['diff_subledger_vs_gl']);

        // 2. Bayar parsial Rp 400.000
        $payment = $this->paymentService->createDraft([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'cash_bank_account_id' => $this->bankAccount->id,
            'total_payment_amount' => '400000.00',
            'payment_date' => now()->toDateString(),
        ], $this->user);

        $this->paymentService->postPayment($payment, [
            [
                'supplier_invoice_id' => $invoice->id,
                'allocated_amount' => '400000.00',
            ],
        ], $this->user);

        // Periksa rekonsiliasi sesudah pembayaran parsial
        $reconAfter = $this->reconciliationService->reconcileApToGl($this->tenant->id);
        $this->assertTrue($reconAfter['is_reconciled']);
        $this->assertEquals('600000.00', $reconAfter['supplier_invoices_outstanding']);
        $this->assertEquals('600000.00', $reconAfter['ap_subledger_balance']);
        $this->assertEquals('600000.00', $reconAfter['gl_ap_control_balance']);
        $this->assertEquals('0.00', $reconAfter['diff_subledger_vs_invoices']);
        $this->assertEquals('0.00', $reconAfter['diff_subledger_vs_gl']);
    }

    public function test_grn_clearing_reconciliation_detects_unbilled_grn_matches_gl(): void
    {
        // 1. Buat dan release PO 10 unit @ 20.000 = Rp 200.000
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '20000.0000',
                ],
            ],
            $this->user
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->user);
        $po = $this->poService->releasePurchaseOrder($po, $this->user);
        $poLine = $po->lines()->firstOrFail();

        // 2. Terima GRN
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-MND-001'],
            [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->user
        );

        $this->grnService->postGrn($grn, $this->user);

        // 3. Rekonsiliasi GR/IR Clearing (belum difakturkan)
        $grIrRecon = $this->reconciliationService->reconcileGrnClearing($this->tenant->id);

        $this->assertTrue($grIrRecon['is_reconciled']);
        $this->assertEquals('200000.00', $grIrRecon['operational_unbilled_grn_amount']);
        $this->assertEquals('200000.00', $grIrRecon['gl_gr_ir_clearing_balance']);
        $this->assertEquals('0.00', $grIrRecon['difference']);
    }
}
