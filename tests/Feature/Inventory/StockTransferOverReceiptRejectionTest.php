<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Models\StockTransferItem;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockTransferOverReceiptRejectionTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockTransferService $service;

    protected InventoryLedgerService $ledgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(StockTransferService::class);
    }

    public function test_service_strictly_rejects_over_receipt_attempt(): void
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
            unitCost: '10000.0000',
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
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $shipped = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);
        $item = $shipped->items()->first();

        // Coba terima 5.0001 unit (melebihi 5.0000 yang dikirim)
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Over-receipt dilarang keras');

        $this->service->receiveTransfer(
            $shipped,
            [
                'items' => [
                    $item->id => [
                        'quantity_received' => '5.0001',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );
    }

    public function test_database_check_constraint_rejects_over_receipt_at_storage_engine(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

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

        $shipped = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);
        $item = $shipped->items()->first();

        // Percobaan bypass layer aplikasi dengan update langsung ke basis data
        $this->expectException(QueryException::class);

        StockTransferItem::where('id', $item->id)->update([
            'quantity_received' => '6.0000', // Dikirim 5.0000, diterima 6.0000 -> melanggar chk_sti_anti_over_receipt
        ]);
    }

    public function test_cannot_receive_negative_quantity(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

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

        $shipped = $this->service->shipTransfer($transfer, (int) $this->userAOwner->id);
        $item = $shipped->items()->first();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak boleh negatif');

        $this->service->receiveTransfer(
            $shipped,
            [
                'items' => [
                    $item->id => [
                        'quantity_received' => '-1.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );
    }
}
