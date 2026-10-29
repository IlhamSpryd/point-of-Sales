<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\AccountingPeriod;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Models\InventoryValuationSnapshot;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\InventoryGlReconciliationService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryGlReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected Product $product;

    protected Ingredient $ingredient;

    protected AccountingPeriod $period;

    protected InventoryLedgerService $ledgerService;

    protected InventoryGlReconciliationService $reconcileService;

    protected ChartOfAccountService $coaService;

    protected JournalEntryService $journalService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->reconcileService = app(InventoryGlReconciliationService::class);
        $this->coaService = app(ChartOfAccountService::class);
        $this->journalService = app(JournalEntryService::class);

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

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'Produk A',
            'standard_cost' => 50000,
            'product_price' => 100000,
            'stock' => 0,
        ]);

        $this->ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Susu',
            'code' => 'ING-001',
            'unit' => 'Liter',
            'storage_type' => 'chilled',
            'cost_per_unit' => 20000,
            'current_stock' => 0,
        ]);

        // 1. Catat saldo di buku stok operasional M4
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->store->id,
            itemType: 'product',
            itemId: (int) $this->product->id,
            quantityDelta: '10.0000',
            type: StockMovementType::InitialSync,
            unitCost: '50000.0000',
            userId: (int) $this->user->id
        );

        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->store->id,
            itemType: 'ingredient',
            itemId: (int) $this->ingredient->id,
            quantityDelta: '5.0000',
            type: IngredientStockMovementTypeEnum::InitialSync,
            unitCost: '20000.0000',
            userId: (int) $this->user->id
        );

        // 2. Sinkronkan saldo akun persediaan di GL M7
        $retailAcc = $this->coaService->getAccountBySystemKey('INVENTORY_RETAIL_PRODUCT', $this->tenant->id);
        $ingredientAcc = $this->coaService->getAccountBySystemKey('INVENTORY_FNB_INGREDIENT', $this->tenant->id);
        $equityAcc = $this->coaService->getAccountBySystemKey('OPENING_BALANCE_EQUITY', $this->tenant->id);

        $this->journalService->postManualJournal(
            new ManualJournalDto(
                tenantId: (int) $this->tenant->id,
                storeId: (int) $this->store->id,
                postingDate: now()->toDateString(),
                idempotencyKey: 'init:inv:gl:rec',
                lines: [
                    new JournalLineDto(
                        accountId: $retailAcc->id,
                        debitAmount: '500000.00',
                        creditAmount: '0.00',
                        description: 'Saldo awal persediaan retail'
                    ),
                    new JournalLineDto(
                        accountId: $ingredientAcc->id,
                        debitAmount: '100000.00',
                        creditAmount: '0.00',
                        description: 'Saldo awal persediaan bahan baku F&B'
                    ),
                    new JournalLineDto(
                        accountId: $equityAcc->id,
                        debitAmount: '0.00',
                        creditAmount: '600000.00',
                        description: 'Ekuitas saldo awal'
                    ),
                ],
                notes: 'Saldo awal persediaan untuk rekonsiliasi',
                journalType: 'OPENING_BALANCE'
            ),
            $this->user
        );
    }

    public function test_reconcile_store_valuation_returns_zero_variance_when_balanced(): void
    {
        $result = $this->reconcileService->reconcileStoreValuation(
            tenantId: (int) $this->tenant->id,
            accountingPeriodId: (int) $this->period->id,
            storeId: (int) $this->store->id
        );

        $this->assertEquals('500000.00', $result['m4_retail_valuation']);
        $this->assertEquals('500000.00', $result['gl_retail_balance']);
        $this->assertEquals('0.00', $result['retail_variance']);

        $this->assertEquals('100000.00', $result['m4_ingredient_valuation']);
        $this->assertEquals('100000.00', $result['gl_ingredient_balance']);
        $this->assertEquals('0.00', $result['ingredient_variance']);

        $this->assertEquals('600000.00', $result['total_m4_valuation']);
        $this->assertEquals('600000.00', $result['total_gl_valuation']);
        $this->assertEquals('0.00', $result['total_variance']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_create_valuation_snapshots_records_rows_in_inventory_valuation_snapshots(): void
    {
        $count = $this->reconcileService->createValuationSnapshots(
            tenantId: (int) $this->tenant->id,
            accountingPeriodId: (int) $this->period->id,
            storeId: (int) $this->store->id
        );

        $this->assertEquals(2, $count);

        $snapshots = InventoryValuationSnapshot::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->get();

        $this->assertCount(2, $snapshots);

        $productSnap = $snapshots->firstWhere('item_type', 'PRODUCT');
        $this->assertNotNull($productSnap);
        $this->assertEquals('10.0000', $productSnap->closing_qty);
        $this->assertEquals('50000.0000', $productSnap->average_unit_cost);
        $this->assertEquals('500000.00', $productSnap->total_valuation_amount);
        $this->assertEquals('MATCHED', $productSnap->reconciliation_status);

        $ingredientSnap = $snapshots->firstWhere('item_type', 'INGREDIENT');
        $this->assertNotNull($ingredientSnap);
        $this->assertEquals('5.0000', $ingredientSnap->closing_qty);
        $this->assertEquals('20000.0000', $ingredientSnap->average_unit_cost);
        $this->assertEquals('100000.00', $ingredientSnap->total_valuation_amount);
        $this->assertEquals('MATCHED', $ingredientSnap->reconciliation_status);
    }
}
