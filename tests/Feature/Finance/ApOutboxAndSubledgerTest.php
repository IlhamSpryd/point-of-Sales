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
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ApOutboxConsumerService;
use App\Modules\Finance\Services\PostingEngineService;
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

class ApOutboxAndSubledgerTest extends TestCase
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

    protected ApOutboxConsumerService $apOutboxConsumer;

    protected PostingEngineService $postingEngine;

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
        $this->postingEngine = app(PostingEngineService::class);

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
            'name' => 'PT Sumber Kopi Maju',
            'code' => 'SUP-001',
            'contact_person' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@sumberkopi.com',
            'payment_terms' => 'NET_30',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'standard_cost' => 50000.00,
            'stock' => 0,
        ]);
    }

    public function test_consumption_of_approved_payable_creates_subledger_credit_and_posts_gl_journal(): void
    {
        // 1. Buat dan release PO 10 unit @ Rp 50.000 = Rp 500.000
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

        // 2. Terima barang melalui GRN
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-SKM-8899'],
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

        // 3. Catat Faktur Pemasok (Supplier Invoice) 10 unit @ Rp 50.000 = Rp 500.000
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'internal_invoice_number' => 'INV-IN-'.date('Ymd').'-001',
            'supplier_invoice_number' => 'SKM-INV-001',
            'normalized_supplier_invoice_number' => SupplierInvoice::normalizeInvoiceNumber('SKM-INV-001'),
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

        // Cocokkan ke GRN (3-Way Match)
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

        // 4. Setujui menjadi kewajiban siap bayar (Approved Payable) -> terbitkan outbox M5 -> M7
        $this->invoiceMatchingService->approvePayable($invoice, $this->user);

        $outbox = AccountingPayableOutbox::where('source_document_id', $invoice->id)
            ->where('event_type', 'purchasing.invoice.approved_payable')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('pending', $outbox->status);

        // 5. Konsumsi outbox via ApOutboxConsumerService
        $processed = $this->apOutboxConsumer->processPendingOutbox($this->tenant->id);
        $this->assertEquals(1, $processed);

        $outbox->refresh();
        $this->assertEquals('processed_by_m7', $outbox->status);
        $this->assertNotNull($outbox->processed_at);

        // 6. Verifikasi ap_ledger_entries
        $ledgerEntry = ApLedgerEntry::where('supplier_invoice_id', $invoice->id)->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertEquals(ApEntryTypeEnum::INVOICE_CREDIT, $ledgerEntry->entry_type);
        $this->assertEquals('0.00', $ledgerEntry->debit_amount);
        $this->assertEquals('500000.00', $ledgerEntry->credit_amount);
        $this->assertEquals('500000.00', $ledgerEntry->running_balance);

        // 7. Verifikasi Journal Entry PR-PUR-03
        $invoice->refresh();
        $this->assertNotNull($invoice->gl_journal_entry_id);
        $this->assertEquals($invoice->gl_journal_entry_id, $ledgerEntry->journal_entry_id);

        $journal = JournalEntry::find($invoice->gl_journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('500000.00', $journal->total_debit);
        $this->assertEquals('500000.00', $journal->total_credit);

        // Debit: GR_IR_CLEARING, Credit: AP_CONTROL
        $lines = $journal->lines()->with('account')->get();
        $grIrLine = $lines->first(fn ($l) => $l->account->system_account_key === 'GR_IR_CLEARING');
        $apLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AP_CONTROL');

        $this->assertNotNull($grIrLine);
        $this->assertNotNull($apLine);
        $this->assertEquals('500000.00', $grIrLine->debit_amount);
        $this->assertEquals('500000.00', $apLine->credit_amount);
    }

    public function test_consumption_of_debit_note_applied_creates_subledger_debit_and_posts_gl_journal(): void
    {
        $eventId = Str::uuid()->toString();
        $outbox = AccountingPayableOutbox::create([
            'tenant_id' => $this->tenant->id,
            'event_id' => $eventId,
            'event_type' => 'DEBIT_NOTE_APPLIED',
            'idempotency_key' => "dn:apply:{$this->tenant->id}:test-1",
            'store_id' => $this->store->id,
            'source_document_type' => 'purchase_debit_note',
            'source_document_id' => 999,
            'source_document_number' => 'DN-001',
            'payload' => [
                'schema_version' => '1.0',
                'event_type' => 'DEBIT_NOTE_APPLIED',
                'event_id' => $eventId,
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'debit_note_id' => 999,
                'debit_note_number' => 'DN-001',
                'supplier_invoice_id' => 101,
                'applied_amount' => '100000.00',
                'applied_at' => now()->toIso8601String(),
            ],
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        $processed = $this->apOutboxConsumer->processPendingOutbox($this->tenant->id);
        $this->assertEquals(1, $processed);

        $outbox->refresh();
        $this->assertEquals('processed_by_m7', $outbox->status);

        $ledgerEntry = ApLedgerEntry::where('idempotency_key', "aple:dn_apply:{$this->tenant->id}:{$outbox->id}")->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertEquals(ApEntryTypeEnum::DEBIT_NOTE_APPLIED, $ledgerEntry->entry_type);
        $this->assertEquals('100000.00', $ledgerEntry->debit_amount);
        $this->assertEquals('0.00', $ledgerEntry->credit_amount);

        // Verifikasi Jurnal PR-PUR-05
        $journal = JournalEntry::find($ledgerEntry->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('100000.00', $journal->total_debit);
        $this->assertEquals('100000.00', $journal->total_credit);

        // Debit: AP_CONTROL, Credit: GR_IR_CLEARING
        $lines = $journal->lines()->with('account')->get();
        $apLine = $lines->first(fn ($l) => $l->account->system_account_key === 'AP_CONTROL');
        $this->assertNotNull($apLine);
        $this->assertEquals('100000.00', $apLine->debit_amount);
    }
}
