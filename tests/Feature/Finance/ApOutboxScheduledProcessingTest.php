<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\ApEntryTypeEnum;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApOutboxScheduledProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected Supplier $supplier;

    protected Product $product;

    protected AccountingPeriod $period;

    protected PurchaseOrderService $poService;

    protected GoodsReceivedNoteService $grnService;

    protected SupplierInvoiceMatchingService $invoiceMatchingService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->invoiceMatchingService = app(SupplierInvoiceMatchingService::class);

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
            'name' => 'PT Mitra Sejahtera Bersama',
            'code' => 'SUP-SCHED-01',
            'email' => 'finance@mitrasejahtera.test',
            'phone' => '081299998888',
            'payment_terms_days' => 30,
            'payment_terms' => 'NET_30',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'standard_cost' => 50000.00,
            'price' => 90000.00,
            'stock' => 0,
        ]);
    }

    public function test_artisan_command_processes_pending_ap_outbox_into_subledger(): void
    {
        // 1. Buat dan release PO
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
                    'unit_price' => '50000.0000',
                ],
            ],
            $this->user
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->user);
        $po = $this->poService->releasePurchaseOrder($po, $this->user);
        $poLine = $po->lines()->firstOrFail();

        // 2. Buat dan posting GRN
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-SCHED-001'],
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

        $postedGrn = $this->grnService->postGrn($grn, $this->user);

        // 3. Buat Supplier Invoice
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'internal_invoice_number' => 'INV-IN-'.date('Ymd').'-SCHED-01',
            'supplier_invoice_number' => 'SKM-INV-SCHED-01',
            'normalized_supplier_invoice_number' => SupplierInvoice::normalizeInvoiceNumber('SKM-INV-SCHED-01'),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'IDR',
            'exchange_rate' => '1.000000',
            'subtotal_amount' => '500000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'additional_charges_amount' => '0.00',
            'total_amount' => '500000.00',
            'debit_note_applied_amount' => '0.00',
            'paid_amount' => '0.00',
            'outstanding_amount' => '500000.00',
            'status' => 'draft',
            'matching_status' => 'unmatched',
            'settlement_status' => 'unpaid',
        ]);

        $grnLine = $postedGrn->lines()->first();
        $invLine = $invoice->lines()->create([
            'tenant_id' => $this->tenant->id,
            'line_number' => 1,
            'purchasable_type' => 'product',
            'purchasable_id' => $this->product->id,
            'purchase_order_line_id' => $poLine->id,
            'invoiced_qty' => '10.0000',
            'unit_price' => '50000.0000',
            'discount_amount' => '0.00',
            'tax_rate' => '0.0000',
            'tax_amount' => '0.00',
            'line_total' => '500000.00',
        ]);

        $invoice->grnMatches()->create([
            'tenant_id' => $this->tenant->id,
            'supplier_invoice_line_id' => $invLine->id,
            'goods_received_note_line_id' => $grnLine->id,
            'matched_qty' => '10.0000',
            'po_unit_price' => '50000.0000',
            'invoice_unit_price' => '50000.0000',
            'qty_variance' => '0.0000',
            'price_variance_amount' => '0.00',
            'tax_variance_amount' => '0.00',
            'variance_status' => 'matched_exact',
            'is_variance_approved' => true,
        ]);

        $invoice->status = 'submitted';
        $invoice->matching_status = 'matched';
        $invoice->save();

        // 4. Approve payable untuk menghasilkan outbox status pending
        $this->invoiceMatchingService->approvePayable($invoice, $this->user);

        $outbox = AccountingPayableOutbox::where('source_document_id', $invoice->id)
            ->where('event_type', 'purchasing.invoice.approved_payable')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('pending', $outbox->status);

        // 5. Jalankan Artisan command finance:process-subledgers
        $this->artisan('finance:process-subledgers', ['--tenant' => (string) $this->tenant->id])
            ->assertSuccessful();

        // 6. Verifikasi outbox berstatus processed
        $outbox->refresh();
        $this->assertNotEquals('pending', $outbox->status);
        $this->assertEquals('processed_by_m7', $outbox->status);
        $this->assertNotNull($outbox->processed_at);

        // 7. Verifikasi record ap_ledger_entries terbentuk
        $ledgerEntry = ApLedgerEntry::where('supplier_invoice_id', $invoice->id)->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertEquals(ApEntryTypeEnum::INVOICE_CREDIT, $ledgerEntry->entry_type);
        $this->assertEquals('500000.00', $ledgerEntry->credit_amount);
        $this->assertEquals('500000.00', $ledgerEntry->running_balance);
    }
}
