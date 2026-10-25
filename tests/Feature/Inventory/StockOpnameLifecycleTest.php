<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatusEnum;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockOpnameLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockOpnameService $service;

    protected InventoryLedgerService $ledgerService;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Biji Kopi Arabika',
                'ingredient_code' => 'ING-COFFEE-01',
                'unit' => 'kg',
                'cost_per_unit' => '120000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(StockOpnameService::class);
    }

    public function test_can_create_start_counting_record_counts_and_submit_for_approval(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Inisialisasi saldo awal
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '10000.0000',
            userId: (int) $this->userAOwner->id
        );

        // 1. Create Draft
        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'notes' => 'Opname akhir pekan Toko A',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $this->assertSame(StockOpnameStatusEnum::Draft, $opname->status);
        $this->assertNull($opname->snapshot_taken_at);
        $this->assertCount(1, $opname->items);
        $this->assertNull($opname->items[0]->counted_quantity);

        // 2. Start Counting (Pin Snapshot)
        $opname = $this->service->startCounting($opname, (int) $this->userAOwner->id);

        $this->assertSame(StockOpnameStatusEnum::Counting, $opname->status);
        $this->assertNotNull($opname->snapshot_taken_at);
        $item = $opname->items[0]->fresh();
        $this->assertSame(0, bccomp((string) $item->snapshot_on_hand, '20.0000', 4));
        $this->assertNotNull($item->snapshot_last_movement_id);

        // 3. Record Counted Quantity
        $opname = $this->service->recordCounts($opname, [
            $item->id => '22.0000', // Kelebihan 2 unit
        ], (int) $this->userAOwner->id);

        $item->refresh();
        $this->assertSame(0, bccomp((string) $item->counted_quantity, '22.0000', 4));
        $this->assertSame(0, bccomp((string) $item->variance_quantity, '2.0000', 4));

        // 4. Submit for Approval
        $opname = $this->service->submitForApproval($opname, (int) $this->userAOwner->id);

        $this->assertSame(StockOpnameStatusEnum::PendingApproval, $opname->status);
        $this->assertNotNull($opname->submitted_at);
        $this->assertSame((int) $this->userAOwner->id, (int) $opname->submitted_by);
    }

    public function test_opname_approval_reconciles_balances_and_creates_movements_only_for_non_zero_variance(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Inisialisasi saldo awal
        // Product A: 10 unit
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '10.0000',
            type: StockMovementType::InitialSync,
            unitCost: '15000.0000',
            userId: (int) $this->userAOwner->id
        );

        // Ingredient A: 5 kg
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'ingredient',
            itemId: (int) $this->ingredientA->id,
            quantityDelta: '5.0000',
            type: IngredientStockMovementTypeEnum::InitialSync,
            unitCost: '120000.0000',
            userId: (int) $this->userAOwner->id
        );

        // 1. Buat Draft opname dengan 2 item
        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        // 2. Mulai Hitung
        $opname = $this->service->startCounting($opname, (int) $this->userAOwner->id);

        $productItem = $opname->items->firstWhere('item_type', 'product');
        $ingredientItem = $opname->items->firstWhere('item_type', 'ingredient');

        // Skenario Hitung Fisik:
        // Product A: fisik 13 unit (Surplus +3 unit)
        // Ingredient A: fisik 5 unit (Cocok 0 unit, ZERO variance)
        $this->service->recordCounts($opname, [
            $productItem->id => '13.0000',
            $ingredientItem->id => '5.0000',
        ], (int) $this->userAOwner->id);

        $opname = $this->service->submitForApproval($opname, (int) $this->userAOwner->id);

        $initialMovementCountProduct = StockMovement::where('product_id', $this->productA->id)->count();
        $initialMovementCountIngredient = IngredientStockMovement::where('ingredient_id', $this->ingredientA->id)->count();

        // 3. Approve Opname
        $approvedOpname = $this->service->approveOpname($opname, (int) $this->userAOwner->id);

        $this->assertSame(StockOpnameStatusEnum::Approved, $approvedOpname->status);
        $this->assertNotNull($approvedOpname->approved_at);
        $this->assertSame((int) $this->userAOwner->id, (int) $approvedOpname->approved_by);

        // Verifikasi Saldo Baru
        $prodBalance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $ingBalance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'ingredient', (int) $this->ingredientA->id);

        $this->assertSame(0, bccomp((string) $prodBalance->quantity, '13.0000', 4));
        $this->assertSame(0, bccomp((string) $ingBalance->quantity, '5.0000', 4));

        // Verifikasi Pergerakan Ledger:
        // Product A: harus bertambah 1 movement OPNAME_ADJUSTMENT sebesar +3
        $this->assertSame($initialMovementCountProduct + 1, StockMovement::where('product_id', $this->productA->id)->count());
        $latestMovement = StockMovement::where('product_id', $this->productA->id)->latest('id')->first();
        $this->assertSame(StockMovementType::OpnameAdjustment, $latestMovement->type);
        $this->assertSame(0, bccomp((string) $latestMovement->quantity, '3.0000', 4));

        // Ingredient A: variance == 0, TIDAK BOLEH dibuatkan movement baru!
        $this->assertSame($initialMovementCountIngredient, IngredientStockMovement::where('ingredient_id', $this->ingredientA->id)->count());
    }

    public function test_can_cancel_opname_in_draft_counting_and_pending_approval_states(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productA->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $cancelled = $this->service->cancelSession($opname, (int) $this->userAOwner->id, 'Sesi dibatalkan');
        $this->assertSame(StockOpnameStatusEnum::Cancelled, $cancelled->status);
        $this->assertStringContainsString('Sesi dibatalkan', $cancelled->notes);
    }

    public function test_submit_without_all_items_counted_is_rejected(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productA->id],
                    ['item_type' => 'ingredient', 'ingredient_id' => (int) $this->ingredientA->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $opname = $this->service->startCounting($opname, (int) $this->userAOwner->id);

        // Hanya hitung product, ingredient dikosongkan
        $productItem = $opname->items->firstWhere('item_type', 'product');
        $this->service->recordCounts($opname, [
            $productItem->id => '5.0000',
        ], (int) $this->userAOwner->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seluruh item wajib diisi hasil hitung fisik sebelum diajukan ke manajer');

        $this->service->submitForApproval($opname, (int) $this->userAOwner->id);
    }
}
