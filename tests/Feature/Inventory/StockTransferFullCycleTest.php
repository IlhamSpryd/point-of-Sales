<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\StockTransferDiscrepancyResolutionEnum;
use App\Enums\StockTransferStatusEnum;
use App\Models\Ingredient;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockTransferFullCycleTest extends TestCase
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
                'name' => 'Biji Kopi Gayo',
                'ingredient_code' => 'ING-GAYO-01',
                'unit' => 'kg',
                'cost_per_unit' => '50000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(StockTransferService::class);
    }

    public function test_full_cycle_success_with_exact_quantities_for_products_and_ingredients(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Inisialisasi saldo di storeA
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '15000.0000',
            userId: (int) $this->userAOwner->id
        );

        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'ingredient',
            itemId: (int) $this->ingredientA->id,
            quantityDelta: '10.0000',
            type: IngredientStockMovementTypeEnum::InitialSync,
            unitCost: '50000.0000',
            userId: (int) $this->userAOwner->id
        );

        // 2. Buat Draft Transfer (storeA -> storeA2)
        $transfer = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'notes' => 'Transfer rutin pasokan cabang 2',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '5.0000',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                        'quantity_requested' => '2.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $this->assertSame(StockTransferStatusEnum::Draft, $transfer->status);
        $this->assertCount(2, $transfer->items);
        $this->assertNull($transfer->shipped_at);

        // 3. Dispatch / Ship Transfer
        $shippedTransfer = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);

        $this->assertSame(StockTransferStatusEnum::InTransit, $shippedTransfer->status);
        $this->assertNotNull($shippedTransfer->shipped_at);
        $this->assertSame((int) $this->userAOwner->id, (int) $shippedTransfer->shipped_by);

        // Verifikasi saldo cabang asal berkurang
        $prodBalanceA = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $ingBalanceA = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'ingredient', (int) $this->ingredientA->id);

        $this->assertNotNull($prodBalanceA);
        $this->assertSame(0, bccomp((string) $prodBalanceA->quantity, '15.0000', 4));
        $this->assertNotNull($ingBalanceA);
        $this->assertSame(0, bccomp((string) $ingBalanceA->quantity, '8.0000', 4));

        // Verifikasi carrying_unit_cost terkunci (OD-06)
        $shippedItems = $shippedTransfer->items()->get();
        $prodItem = $shippedItems->firstWhere('item_type', 'product');
        $ingItem = $shippedItems->firstWhere('item_type', 'ingredient');

        $this->assertSame('15000.0000', (string) $prodItem->carrying_unit_cost);
        $this->assertSame('50000.0000', (string) $ingItem->carrying_unit_cost);

        // Saldo cabang tujuan belum bertambah saat IN_TRANSIT
        $prodBalanceA2 = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'product', (int) $this->productA->id);
        $this->assertTrue($prodBalanceA2 === null || bccomp((string) $prodBalanceA2->quantity, '0', 4) === 0);

        // 4. Receive Transfer di Cabang Tujuan (storeA2) secara penuh (no shortage)
        $receivedTransfer = $this->service->receiveTransfer(
            $shippedTransfer,
            [
                'notes' => 'Penerimaan lengkap tanpa kendala',
                'items' => [
                    $prodItem->id => [
                        'quantity_received' => '5.0000',
                        'discrepancy_resolution' => null,
                    ],
                    $ingItem->id => [
                        'quantity_received' => '2.0000',
                        'discrepancy_resolution' => null,
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->assertSame(StockTransferStatusEnum::Received, $receivedTransfer->status);
        $this->assertNotNull($receivedTransfer->received_at);
        $this->assertSame((int) $this->userAOwner->id, (int) $receivedTransfer->received_by);

        // Verifikasi saldo cabang tujuan bertambah dengan carrying_unit_cost asal (OD-06)
        $prodBalanceA2Final = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'product', (int) $this->productA->id);
        $ingBalanceA2Final = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'ingredient', (int) $this->ingredientA->id);

        $this->assertNotNull($prodBalanceA2Final);
        $this->assertSame(0, bccomp((string) $prodBalanceA2Final->quantity, '5.0000', 4));
        $this->assertSame(0, bccomp((string) $prodBalanceA2Final->average_unit_cost, '15000.0000', 4));

        $this->assertNotNull($ingBalanceA2Final);
        $this->assertSame(0, bccomp((string) $ingBalanceA2Final->quantity, '2.0000', 4));
        $this->assertSame(0, bccomp((string) $ingBalanceA2Final->average_unit_cost, '50000.0000', 4));

        // Verifikasi pergerakan ledger di kedua cabang
        $transferOutMovements = StockMovement::withoutGlobalScopes()
            ->where('type', StockMovementType::TransferOut->value)
            ->where('store_id', $this->storeA->id)
            ->get();
        $transferInMovements = StockMovement::withoutGlobalScopes()
            ->where('type', StockMovementType::TransferIn->value)
            ->where('store_id', $this->storeA2->id)
            ->get();

        $this->assertCount(1, $transferOutMovements);
        $this->assertCount(1, $transferInMovements);
    }

    public function test_shortage_resolution_return_to_source_refunds_sender_store(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Saldo awal 20 unit di storeA
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '20000.0000',
            userId: (int) $this->userAOwner->id
        );

        $transfer = $this->service->createDraft(
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
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $shippedTransfer = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);
        $item = $shippedTransfer->items()->first();

        // Cabang asal berkurang jadi 10
        $balanceAAfterShip = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceAAfterShip);
        $this->assertSame(0, bccomp((string) $balanceAAfterShip->quantity, '10.0000', 4));

        // Cabang tujuan hanya menerima 7 (selisih 3). Resolusi: RETURN_TO_SOURCE
        $receivedTransfer = $this->service->receiveTransfer(
            $shippedTransfer,
            [
                'items' => [
                    $item->id => [
                        'quantity_received' => '7.0000',
                        'discrepancy_resolution' => StockTransferDiscrepancyResolutionEnum::ReturnToSource,
                        'discrepancy_reason_code' => 'LEFTOVER_AT_TRUCK',
                        'notes' => '3 unit tertinggal di armada dan dibawa balik ke asal',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->assertSame(StockTransferStatusEnum::Received, $receivedTransfer->status);

        $updatedItem = $receivedTransfer->items()->first();
        $this->assertSame('7.0000', (string) $updatedItem->quantity_received);
        $this->assertSame('3.0000', (string) $updatedItem->discrepancy_quantity);
        $this->assertSame(StockTransferDiscrepancyResolutionEnum::ReturnToSource, $updatedItem->discrepancy_resolution);

        // Saldo cabang tujuan (storeA2) bertambah 7
        $balanceA2 = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceA2);
        $this->assertSame(0, bccomp((string) $balanceA2->quantity, '7.0000', 4));

        // Saldo cabang asal (storeA) dipulihkan sebesar 3 (10 + 3 = 13)!
        $balanceAFinal = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceAFinal);
        $this->assertSame(0, bccomp((string) $balanceAFinal->quantity, '13.0000', 4));

        // Verifikasi pergerakan transfer_return_in di storeA
        $returnMovements = StockMovement::where('type', StockMovementType::TransferReturnIn->value)
            ->where('store_id', $this->storeA->id)
            ->get();
        $this->assertCount(1, $returnMovements);
        $this->assertSame(3, (int) $returnMovements->first()->quantity);
    }

    public function test_shortage_resolution_write_off_in_transit_does_not_refund_sender_store(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Saldo awal 20 unit di storeA
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '20000.0000',
            userId: (int) $this->userAOwner->id
        );

        $transfer = $this->service->createDraft(
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
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $shippedTransfer = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);
        $item = $shippedTransfer->items()->first();

        // Cabang asal berkurang jadi 10
        $balanceAAfterShip = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceAAfterShip);
        $this->assertSame(0, bccomp((string) $balanceAAfterShip->quantity, '10.0000', 4));

        // Cabang tujuan hanya menerima 8 (selisih 2). Resolusi: WRITE_OFF_IN_TRANSIT (rusak di jalan)
        $receivedTransfer = $this->service->receiveTransfer(
            $shippedTransfer,
            [
                'items' => [
                    $item->id => [
                        'quantity_received' => '8.0000',
                        'discrepancy_resolution' => StockTransferDiscrepancyResolutionEnum::WriteOffInTransit,
                        'discrepancy_reason_code' => 'DAMAGED_IN_TRANSIT',
                        'notes' => '2 unit pecah saat pengiriman',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->assertSame(StockTransferStatusEnum::Received, $receivedTransfer->status);

        $updatedItem = $receivedTransfer->items()->first();
        $this->assertSame('8.0000', (string) $updatedItem->quantity_received);
        $this->assertSame('2.0000', (string) $updatedItem->discrepancy_quantity);
        $this->assertSame(StockTransferDiscrepancyResolutionEnum::WriteOffInTransit, $updatedItem->discrepancy_resolution);

        // Saldo cabang tujuan (storeA2) bertambah 8
        $balanceA2 = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceA2);
        $this->assertSame(0, bccomp((string) $balanceA2->quantity, '8.0000', 4));

        // Saldo cabang asal (storeA) tetap 10 (tidak ada refund karena dihapusbukukan)
        $balanceAFinal = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceAFinal);
        $this->assertSame(0, bccomp((string) $balanceAFinal->quantity, '10.0000', 4));

        // Tidak ada transfer_return_in di storeA
        $returnMovements = StockMovement::where('type', StockMovementType::TransferReturnIn->value)
            ->where('store_id', $this->storeA->id)
            ->get();
        $this->assertCount(0, $returnMovements);
    }

    public function test_can_cancel_draft_transfer_without_ledger_movements(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $transfer = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $cancelled = $this->service->cancelTransfer(
            $transfer,
            (int) $this->userAOwner->id,
            'Salah buat permintaan'
        );

        $this->assertSame(StockTransferStatusEnum::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertStringContainsString('Salah buat permintaan', (string) $cancelled->notes);

        // Tidak ada pergerakan ledger
        $movements = StockMovement::where('store_id', $this->storeA->id)->get();
        $this->assertCount(0, $movements);
    }

    public function test_can_cancel_in_transit_transfer_and_restores_sender_stock(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Inisialisasi 20 unit
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '15000.0000',
            userId: (int) $this->userAOwner->id
        );

        $transfer = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '8.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $shipped = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);

        // Saldo berkurang menjadi 12 (20 - 8)
        $balanceAfterShip = ProductStockBalance::where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->first();
        $this->assertSame(0, bccomp((string) $balanceAfterShip->quantity, '12.0000', 4));

        // Batalkan transfer saat IN_TRANSIT (misal kendaraan pengiriman mengalami mogok total)
        $cancelled = $this->service->cancelTransfer(
            $shipped,
            (int) $this->userAOwner->id,
            'Kendaraan logistik rusak, barang dikembalikan ke gudang asal'
        );

        $this->assertSame(StockTransferStatusEnum::Cancelled, $cancelled->status);

        // Saldo cabang asal dipulihkan kembali ke 20.0000
        $balanceRestored = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertNotNull($balanceRestored);
        $this->assertSame(0, bccomp((string) $balanceRestored->quantity, '20.0000', 4));

        // Terdapat mutasi pemulihan bertipe transfer_return_in
        $restoreMovements = StockMovement::where('type', StockMovementType::TransferReturnIn->value)
            ->where('store_id', $this->storeA->id)
            ->get();
        $this->assertCount(1, $restoreMovements);
        $this->assertSame(8, (int) $restoreMovements->first()->quantity);
    }
}
