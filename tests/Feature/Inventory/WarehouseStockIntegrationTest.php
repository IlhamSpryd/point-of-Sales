<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockTransferDiscrepancyResolutionEnum;
use App\Enums\StockTransferStatusEnum;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Core\Models\Company;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Products\Models\Category;
use App\Modules\Settings\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WarehouseStockIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;
    private Company $company;
    private Warehouse $centralWarehouse;
    private Store $outletStore;
    private Store $originStore;
    private User $operatorUser;
    private Product $product;
    private InventoryLedgerService $ledgerService;
    private StockTransferService $transferService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Logistics Enterprise Corp',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'company_code' => 'CMP-LOG-01',
            'company_name' => 'PT Logistik Distribusi Utama',
            'legal_name' => 'PT Logistik Distribusi Utama',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->originStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Distribution Hub A',
            'code' => 'HUB-A',
        ]);

        $this->outletStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Retail Outlet Grand City',
            'code' => 'OUT-GC-01',
        ]);

        $this->centralWarehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'store_id' => $this->originStore->id,
            'warehouse_code' => 'DC-CENTRAL-01',
            'warehouse_name' => 'Central Distribution Center Jakarta',
            'warehouse_type' => 'CENTRAL',
            'is_active' => true,
        ]);

        $this->operatorUser = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Warehouse Logistics Officer',
            'email' => 'logistics.'.uniqid().'@example.com',
            'password' => bcrypt('Secret123!'),
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Consumer Electronics',
        ]);

        $this->product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Wireless Bluetooth Speaker',
            'product_code' => 'PRD-SPK-01',
            'product_price' => 450000.00,
            'product_type' => 'standard',
            'is_active' => true,
        ]);

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->transferService = app(StockTransferService::class);
    }

    public function test_inventory_ledger_records_movement_with_warehouse_id(): void
    {
        $result = $this->ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->originStore->id,
            itemType: 'product',
            itemId: $this->product->id,
            quantityDelta: '100.0000',
            type: StockMovementType::AdjustmentIn,
            unitCost: '300000.00',
            reason: 'Inbound initial shipment to Central DC',
            userId: $this->operatorUser->id,
            warehouseId: $this->centralWarehouse->id
        );

        $this->assertNotNull($result['movement']);

        $movement = StockMovement::withoutGlobalScopes()->find($result['movement']->id);
        $this->assertNotNull($movement);
        $this->assertEquals($this->centralWarehouse->id, $movement->warehouse_id);
        $this->assertSame('Central Distribution Center Jakarta', $movement->warehouse->warehouse_name);
        $this->assertEquals($this->company->id, $movement->warehouse->company_id);
    }

    public function test_stock_transfer_from_central_warehouse_to_store(): void
    {
        // 1. Initial stock in Origin Hub
        $this->ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->originStore->id,
            itemType: 'product',
            itemId: $this->product->id,
            quantityDelta: '50.0000',
            type: StockMovementType::AdjustmentIn,
            unitCost: '300000.00',
            warehouseId: $this->centralWarehouse->id
        );

        // 2. Create Draft Transfer from Central Warehouse
        $transfer = $this->transferService->createDraft(
            tenantId: $this->tenant->id,
            fromStoreId: $this->originStore->id,
            toStoreId: $this->outletStore->id,
            data: [
                'from_warehouse_id' => $this->centralWarehouse->id,
                'notes' => 'Transfer DC to Outlet Grand City',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->product->id,
                        'quantity_requested' => '20.0000',
                    ],
                ],
            ],
            userId: $this->operatorUser->id
        );

        $this->assertSame(StockTransferStatusEnum::Draft, $transfer->status);
        $this->assertEquals($this->centralWarehouse->id, $transfer->from_warehouse_id);
        $this->assertSame('Central Distribution Center Jakarta', $transfer->fromWarehouse->warehouse_name);

        // 3. Ship Transfer
        $shipped = $this->transferService->shipTransfer(
            transfer: $transfer,
            userId: $this->operatorUser->id,
            shippedQuantities: [$transfer->items->first()->id => '20.0000']
        );

        $this->assertSame(StockTransferStatusEnum::InTransit, $shipped->status);

        // Verify outbound movement tagged with central warehouse
        $outMovement = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->originStore->id)
            ->where('warehouse_id', $this->centralWarehouse->id)
            ->where('type', StockMovementType::TransferOut)
            ->latest('id')
            ->first();

        $this->assertNotNull($outMovement);
        $this->assertEquals($this->centralWarehouse->id, $outMovement->warehouse_id);

        // 4. Receive Transfer at Outlet Store
        $received = $this->transferService->receiveTransfer(
            transfer: $shipped,
            receiptData: [
                'items' => [
                    [
                        'item_id' => $shipped->items->first()->id,
                        'quantity_received' => '20.0000',
                        'discrepancy_resolution' => StockTransferDiscrepancyResolutionEnum::None->value,
                    ],
                ],
            ],
            userId: $this->operatorUser->id
        );

        $this->assertSame(StockTransferStatusEnum::Received, $received->status);

        // Verify balance in outlet store is 20
        $outletBalance = $this->ledgerService->getBalance(
            tenantId: $this->tenant->id,
            storeId: $this->outletStore->id,
            itemType: 'product',
            itemId: $this->product->id
        );
        $this->assertNotNull($outletBalance);
        $this->assertSame(0, bccomp('20.0000', (string) $outletBalance->quantity, 4));
    }

    public function test_composite_foreign_key_blocks_foreign_tenant_warehouse_association(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Rogue Competitor Corp',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($otherTenant->id);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'company_code' => 'ROGUE-01',
            'company_name' => 'Rogue Company',
        ]);

        $rogueWarehouse = Warehouse::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'warehouse_code' => 'ROGUE-WH',
            'warehouse_name' => 'Rogue Warehouse',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->expectException(QueryException::class);

        // Malicious attempt to link Tenant A stock movement to Rogue Warehouse belonging to Tenant B
        StockMovement::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->originStore->id,
            'warehouse_id' => $rogueWarehouse->id, // CROSS-TENANT VIOLATION!
            'product_id' => $this->product->id,
            'type' => StockMovementType::AdjustmentIn,
            'quantity' => 10,
            'unit_cost' => '100000.0000',
            'idempotency_key' => 'rogue-comp-'.uniqid(),
        ]);
    }

    public function test_inter_company_warehouse_to_warehouse_transfer_within_same_tenant(): void
    {
        // 1. Setup second legal company under the same tenant
        $companyBeta = Company::create([
            'tenant_id' => $this->tenant->id,
            'company_code' => 'CMP-BETA-02',
            'company_name' => 'PT Niaga Distribusi Timur',
            'legal_name' => 'PT Niaga Distribusi Timur TBK',
            'is_default' => false,
            'is_active' => true,
        ]);

        $storeBeta = Store::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyBeta->id,
            'name' => 'Warehouse Store East',
            'code' => 'HUB-EAST-01',
        ]);

        $warehouseBeta = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyBeta->id,
            'store_id' => $storeBeta->id,
            'warehouse_code' => 'WH-EAST-SURABAYA',
            'warehouse_name' => 'Surabaya Fulfillment Hub',
            'warehouse_type' => 'REGIONAL',
            'is_active' => true,
        ]);

        // 2. Inbound stock to Central DC (Company Alpha)
        $this->ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->originStore->id,
            itemType: 'product',
            itemId: $this->product->id,
            quantityDelta: '60.0000',
            type: StockMovementType::AdjustmentIn,
            unitCost: '300000.00',
            warehouseId: $this->centralWarehouse->id
        );

        // 3. Create Draft Transfer from Central DC (Company Alpha) to Surabaya Hub (Company Beta)
        $transfer = $this->transferService->createDraft(
            tenantId: $this->tenant->id,
            fromStoreId: $this->originStore->id,
            toStoreId: $storeBeta->id,
            data: [
                'from_warehouse_id' => $this->centralWarehouse->id,
                'to_warehouse_id' => $warehouseBeta->id,
                'notes' => 'Inter-company transfer Alpha DC to Beta East',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->product->id,
                        'quantity_requested' => '25.0000',
                    ],
                ],
            ],
            userId: $this->operatorUser->id
        );

        $this->assertSame(StockTransferStatusEnum::Draft, $transfer->status);
        $this->assertEquals($this->centralWarehouse->id, $transfer->from_warehouse_id);
        $this->assertEquals($warehouseBeta->id, $transfer->to_warehouse_id);

        // 4. Ship Transfer
        $shipped = $this->transferService->shipTransfer(
            transfer: $transfer,
            userId: $this->operatorUser->id,
            shippedQuantities: [$transfer->items->first()->id => '25.0000']
        );
        $this->assertSame(StockTransferStatusEnum::InTransit, $shipped->status);

        // 5. Receive Transfer
        $received = $this->transferService->receiveTransfer(
            transfer: $shipped,
            receiptData: [
                'items' => [
                    [
                        'item_id' => $shipped->items->first()->id,
                        'quantity_received' => '25.0000',
                        'discrepancy_resolution' => StockTransferDiscrepancyResolutionEnum::None->value,
                    ],
                ],
            ],
            userId: $this->operatorUser->id
        );
        $this->assertSame(StockTransferStatusEnum::Received, $received->status);

        // 6. Verify destination store balance
        $destBalance = $this->ledgerService->getBalance(
            tenantId: $this->tenant->id,
            storeId: $storeBeta->id,
            itemType: 'product',
            itemId: $this->product->id
        );
        $this->assertNotNull($destBalance);
        $this->assertSame(0, bccomp('25.0000', (string) $destBalance->quantity, 4));
    }
}
