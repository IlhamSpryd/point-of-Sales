<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrnToInvoiceThreeWayMatchAccountingTest extends TestCase
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
        $this->postingEngine = app(PostingEngineService::class);

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

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Supplier Utama',
            'code' => 'SUP-001',
            'status' => 'active',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'Kopi Arabika 1kg',
            'standard_cost' => 50000,
            'product_price' => 100000,
            'stock' => 0,
        ]);
    }

    public function test_grn_posting_emits_outbox_and_posts_debit_inventory_credit_gr_ir_clearing(): void
    {
        // 1. Buat dan release Purchase Order
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
            ['supplier_delivery_note_no' => 'SJ-12345'],
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

        // 3. Post GRN
        $postedGrn = $this->grnService->postGrn($grn, $this->user);

        $this->assertTrue($postedGrn->isPosted());

        // Verifikasi Outbox record
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'purchasing.grn.posted')
            ->where('source_id', $postedGrn->id)
            ->first();

        $this->assertNotNull($outbox, 'Outbox purchasing.grn.posted harus tercatat.');
        $this->assertEquals('PROCESSED', $outbox->status->value);

        // Verifikasi Journal Entry GL yang diposting oleh PostingEngine
        $journal = JournalEntry::find($outbox->journal_entry_id);

        $this->assertNotNull($journal, 'Journal GL GRN harus berhasil dibuat.');
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals('500000.00', $journal->total_debit);
        $this->assertEquals('500000.00', $journal->total_credit);

        // Verifikasi baris debit persediaan & kredit GR/IR clearing
        $lines = $journal->lines()->get();
        $this->assertCount(2, $lines);

        $inventoryLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $grIrLine = $lines->first(fn ($l) => $l->account->system_account_key === 'GR_IR_CLEARING');

        $this->assertNotNull($inventoryLine);
        $this->assertNotNull($grIrLine);

        $this->assertEquals('500000.00', $inventoryLine->debit_amount);
        $this->assertEquals('500000.00', $grIrLine->credit_amount);
    }

    public function test_grn_reversal_posts_symmetric_journal_debit_gr_ir_credit_inventory(): void
    {
        // 1. Buat dan release Purchase Order
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
                    'ordered_qty' => '5.0000',
                    'unit_price' => '60000.0000',
                ],
            ],
            $this->user
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->user);
        $po = $this->poService->releasePurchaseOrder($po, $this->user);
        $poLine = $po->lines()->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-REV-01'],
            [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => '5.0000',
                    'accepted_qty' => '5.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->user
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->user);

        // 2. Batalkan / Reverse GRN
        $reversedGrn = $this->grnService->reverseGrn($postedGrn, $this->user, 'Barang salah kirim oleh supplier');

        $this->assertTrue($reversedGrn->isReversed());

        // Verifikasi Outbox reversal
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'purchasing.grn.reversed')
            ->where('source_id', $reversedGrn->id)
            ->first();

        $this->assertNotNull($outbox, 'Outbox purchasing.grn.reversed harus tercatat.');
        $this->assertEquals('PROCESSED', $outbox->status->value);

        // Verifikasi Journal GL Reversal
        $journal = JournalEntry::find($outbox->journal_entry_id);

        $this->assertNotNull($journal, 'Journal GL Reversal GRN harus berhasil dibuat.');
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals('300000.00', $journal->total_debit);
        $this->assertEquals('300000.00', $journal->total_credit);

        $lines = $journal->lines;
        $inventoryLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $grIrLine = $lines->first(fn ($l) => $l->account->system_account_key === 'GR_IR_CLEARING');

        $this->assertNotNull($inventoryLine);
        $this->assertNotNull($grIrLine);

        // Reversal: Debit GR/IR, Credit Inventory
        $this->assertEquals('300000.00', $grIrLine->debit_amount);
        $this->assertEquals('300000.00', $inventoryLine->credit_amount);
    }
}
