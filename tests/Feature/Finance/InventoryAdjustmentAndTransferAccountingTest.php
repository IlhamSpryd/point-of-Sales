<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\StockAdjustmentReasonEnum;
use App\Enums\StockMovementType;
use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAdjustmentAndTransferAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $storeA;

    protected $storeB;

    protected User $user;

    protected Product $product;

    protected AccountingPeriod $period;

    protected InventoryLedgerService $ledgerService;

    protected StockAdjustmentService $adjustmentService;

    protected StockTransferService $transferService;

    protected ChartOfAccountService $coaService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->storeA = $this->defaultStore;
        $this->storeB = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Surabaya',
            'is_active' => true,
        ]);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->adjustmentService = app(StockAdjustmentService::class);
        $this->transferService = app(StockTransferService::class);
        $this->coaService = app(ChartOfAccountService::class);

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
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'Sirup Karamel 1L',
            'standard_cost' => 20000,
            'product_price' => 45000,
            'stock' => 0,
        ]);

        // Inisialisasi saldo stok di ledger M4
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->product->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '20000.0000',
            userId: (int) $this->user->id
        );
    }

    public function test_stock_adjustment_waste_posts_debit_expense_credit_inventory(): void
    {
        // 1. Buat dan posting draft penyesuaian stok (OUT / Rusak)
        $draft = $this->adjustmentService->createDraft(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->storeA->id,
            data: [
                'reason_code' => StockAdjustmentReasonEnum::Damaged,
                'notes' => 'Botol pecah saat penataan rak',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->product->id,
                        'direction' => 'OUT',
                        'quantity' => '2.0000',
                        'unit_cost_snapshot' => '20000.0000',
                        'reason_note' => 'Pecah di lorong 3',
                    ],
                ],
            ],
            userId: (int) $this->user->id
        );

        $posted = $this->adjustmentService->postAdjustment($draft, (int) $this->user->id);
        $this->assertTrue($posted->isPosted());

        // Verifikasi outbox
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'inventory.adjustment.posted')
            ->where('source_id', $posted->id)
            ->first();

        $this->assertNotNull($outbox, 'Outbox inventory.adjustment.posted harus ada.');
        $this->assertEquals('PROCESSED', $outbox->status->value);

        // Verifikasi Journal GL
        $journal = JournalEntry::find($outbox->journal_entry_id);

        $this->assertNotNull($journal, 'Journal GL penyesuaian stok harus diposting.');
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals('40000.00', $journal->total_debit);
        $this->assertEquals('40000.00', $journal->total_credit);

        $lines = $journal->lines;
        $wasteLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_WASTE_EXPENSE');
        $inventoryLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');

        $this->assertNotNull($wasteLine);
        $this->assertNotNull($inventoryLine);

        $this->assertEquals('40000.00', $wasteLine->debit_amount);
        $this->assertEquals('40000.00', $inventoryLine->credit_amount);
    }

    public function test_stock_adjustment_in_posts_debit_inventory_credit_adjustment_gain(): void
    {
        $draft = $this->adjustmentService->createDraft(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->storeA->id,
            data: [
                'reason_code' => StockAdjustmentReasonEnum::Found,
                'notes' => 'Kelebihan stok fisik ditemukan di gudang',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->product->id,
                        'direction' => 'IN',
                        'quantity' => '3.0000',
                        'unit_cost_snapshot' => '20000.0000',
                    ],
                ],
            ],
            userId: (int) $this->user->id
        );

        $posted = $this->adjustmentService->postAdjustment($draft, (int) $this->user->id);
        $this->assertTrue($posted->isPosted());

        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'inventory.adjustment.posted')
            ->where('source_id', $posted->id)
            ->first();
        $this->assertNotNull($outbox);
        $journal = JournalEntry::find($outbox->journal_entry_id);

        $this->assertNotNull($journal);
        $this->assertEquals('60000.00', $journal->total_debit);
        $this->assertEquals('60000.00', $journal->total_credit);

        $lines = $journal->lines;
        $inventoryLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $wasteLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_WASTE_EXPENSE');

        $this->assertNotNull($inventoryLine);
        $this->assertNotNull($wasteLine);

        $this->assertEquals('60000.00', $inventoryLine->debit_amount);
        $this->assertEquals('60000.00', $wasteLine->credit_amount);
    }

    public function test_inter_branch_stock_transfer_shipped_and_received_cycle(): void
    {
        // 1. Buat Draft Transfer
        $transfer = $this->transferService->createDraft(
            tenantId: (int) $this->tenant->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeB->id,
            data: [
                'notes' => 'Transfer stok barang ke cabang Surabaya',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->product->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->user->id
        );

        // 2. Ship Transfer
        $shipped = $this->transferService->shipTransfer($transfer, (int) $this->user->id);
        $this->assertNotNull($shipped->shipped_at);

        // Verifikasi Journal Shipped
        $shippedOutbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'inventory.transfer.shipped')
            ->where('source_id', $shipped->id)
            ->first();
        $this->assertNotNull($shippedOutbox);
        $shippedJournal = JournalEntry::find($shippedOutbox->journal_entry_id);

        $this->assertNotNull($shippedJournal, 'Journal shipped harus diposting.');
        $this->assertEquals('100000.00', $shippedJournal->total_debit);
        $this->assertEquals('100000.00', $shippedJournal->total_credit);

        $lines = $shippedJournal->lines;
        $inTransitLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_IN_TRANSIT');
        $inventoryLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');

        $this->assertNotNull($inTransitLine);
        $this->assertNotNull($inventoryLine);

        // Shipped: Debit In-Transit, Credit Inventory
        $this->assertEquals('100000.00', $inTransitLine->debit_amount);
        $this->assertEquals('100000.00', $inventoryLine->credit_amount);

        // 3. Receive Transfer di toko tujuan
        $item = $shipped->items()->first();
        $received = $this->transferService->receiveTransfer(
            $shipped,
            [
                'items' => [
                    [
                        'item_id' => $item->id,
                        'quantity_received' => '5.0000',
                    ],
                ],
            ],
            (int) $this->user->id
        );

        $this->assertNotNull($received->received_at);

        // Verifikasi Journal Received
        $receivedOutbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'inventory.transfer.received')
            ->where('source_id', $received->id)
            ->first();
        $this->assertNotNull($receivedOutbox);
        $receivedJournal = JournalEntry::find($receivedOutbox->journal_entry_id);

        $this->assertNotNull($receivedJournal, 'Journal received harus diposting.');
        $this->assertEquals('100000.00', $receivedJournal->total_debit);
        $this->assertEquals('100000.00', $receivedJournal->total_credit);

        $lines = $receivedJournal->lines;
        $inventoryLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $inTransitLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_IN_TRANSIT');

        $this->assertNotNull($inventoryLine);
        $this->assertNotNull($inTransitLine);

        // Received: Debit Inventory (toko tujuan), Credit In-Transit
        $this->assertEquals('100000.00', $inventoryLine->debit_amount);
        $this->assertEquals('100000.00', $inTransitLine->credit_amount);
    }
}
