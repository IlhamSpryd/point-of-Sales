<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatusEnum;
use App\Models\Ingredient;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockTransferCrossBranchDeadlockTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockTransferService $service;

    protected InventoryLedgerService $ledgerService;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kopi Robusta Lampung',
                'ingredient_code' => 'ING-ROB-01',
                'unit' => 'kg',
                'cost_per_unit' => '40000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(StockTransferService::class);
    }

    public function test_cross_branch_transfers_with_opposing_directions_execute_without_deadlocks(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Inisialisasi stok di kedua cabang: storeA dan storeA2
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '50.0000',
            type: StockMovementType::InitialSync,
            unitCost: '10000.0000',
            userId: (int) $this->userAOwner->id
        );

        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'ingredient',
            itemId: (int) $this->ingredientA->id,
            quantityDelta: '50.0000',
            type: IngredientStockMovementTypeEnum::InitialSync,
            unitCost: '40000.0000',
            userId: (int) $this->userAOwner->id
        );

        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA2->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '50.0000',
            type: StockMovementType::InitialSync,
            unitCost: '10000.0000',
            userId: (int) $this->userAOwner->id
        );

        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA2->id,
            itemType: 'ingredient',
            itemId: (int) $this->ingredientA->id,
            quantityDelta: '50.0000',
            type: IngredientStockMovementTypeEnum::InitialSync,
            unitCost: '40000.0000',
            userId: (int) $this->userAOwner->id
        );

        // 2. Transfer 1: Cabang A -> Cabang A2
        $transfer1 = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '10.0000',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        // 3. Transfer 2: Cabang A2 -> Cabang A (arah berlawanan secara simultan)
        $transfer2 = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA2->id,
            toStoreId: (int) $this->storeA->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                        'quantity_requested' => '8.0000',
                    ],
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '12.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        // 4. Ship kedua transfer
        $shipped1 = $this->service->shipTransfer($transfer1, (int) $this->userAOwner->id);
        $shipped2 = $this->service->shipTransfer($transfer2, (int) $this->userAOwner->id);

        $this->assertSame(StockTransferStatusEnum::InTransit, $shipped1->status);
        $this->assertSame(StockTransferStatusEnum::InTransit, $shipped2->status);

        // 5. Receive Transfer 1 (A -> A2) dan Transfer 2 (A2 -> A)
        $items1 = $shipped1->items()->get();
        $items2 = $shipped2->items()->get();

        $receiptPayload1 = ['items' => []];
        foreach ($items1 as $it) {
            $receiptPayload1['items'][$it->id] = [
                'quantity_received' => (string) $it->quantity_shipped,
                'discrepancy_resolution' => null,
            ];
        }

        $receiptPayload2 = ['items' => []];
        foreach ($items2 as $it) {
            $receiptPayload2['items'][$it->id] = [
                'quantity_received' => (string) $it->quantity_shipped,
                'discrepancy_resolution' => null,
            ];
        }

        $received1 = $this->service->receiveTransfer($shipped1, $receiptPayload1, (int) $this->userAOwner->id);
        $received2 = $this->service->receiveTransfer($shipped2, $receiptPayload2, (int) $this->userAOwner->id);

        $this->assertSame(StockTransferStatusEnum::Received, $received1->status);
        $this->assertSame(StockTransferStatusEnum::Received, $received2->status);

        // 6. Verifikasi integritas konservasi massa total di seluruh sistem
        // Product:
        // Awal: storeA 50, storeA2 50. Total = 100.
        // Transfer 1: A -> A2 kirim 10, terima 10.
        // Transfer 2: A2 -> A kirim 12, terima 12.
        // Net storeA product: 50 - 10 + 12 = 52.
        // Net storeA2 product: 50 + 10 - 12 = 48.
        // Total = 100.
        $balAProduct = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $balA2Product = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'product', (int) $this->productA->id);

        $this->assertNotNull($balAProduct);
        $this->assertNotNull($balA2Product);
        $this->assertSame(0, bccomp((string) $balAProduct->quantity, '52.0000', 4));
        $this->assertSame(0, bccomp((string) $balA2Product->quantity, '48.0000', 4));
        $this->assertSame(0, bccomp(bcadd((string) $balAProduct->quantity, (string) $balA2Product->quantity, 4), '100.0000', 4));

        // Ingredient:
        // Awal: storeA 50, storeA2 50. Total = 100.
        // Transfer 1: A -> A2 kirim 5, terima 5.
        // Transfer 2: A2 -> A kirim 8, terima 8.
        // Net storeA ingredient: 50 - 5 + 8 = 53.
        // Net storeA2 ingredient: 50 + 5 - 8 = 47.
        // Total = 100.
        $balAIng = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'ingredient', (int) $this->ingredientA->id);
        $balA2Ing = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'ingredient', (int) $this->ingredientA->id);

        $this->assertNotNull($balAIng);
        $this->assertNotNull($balA2Ing);
        $this->assertSame(0, bccomp((string) $balAIng->quantity, '53.0000', 4));
        $this->assertSame(0, bccomp((string) $balA2Ing->quantity, '47.0000', 4));
        $this->assertSame(0, bccomp(bcadd((string) $balAIng->quantity, (string) $balA2Ing->quantity, 4), '100.0000', 4));
    }
}
