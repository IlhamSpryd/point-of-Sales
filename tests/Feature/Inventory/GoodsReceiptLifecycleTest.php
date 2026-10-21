<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\GoodsReceiptStatusEnum;
use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\IngredientStockBalance;
use App\Models\IngredientStockMovement;
use App\Models\Product;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class GoodsReceiptLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected GoodsReceiptService $service;

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
                'sku' => 'ING-COFFEE-01',
                'unit' => 'kg',
                'cost_per_unit' => '50000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(GoodsReceiptService::class);
    }

    public function test_can_create_update_and_cancel_goods_receipt_draft(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Create Draft
        $receipt = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'supplier_reference' => 'PT Kopi Nusantara',
                'purchase_order_reference' => 'PO-2026-001',
                'notes' => 'Draft penerimaan awal',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '10.0000',
                        'unit_cost' => '25000.0000',
                        'batch_number' => 'LOT-PROD-001',
                        'expiry_date' => '2027-12-31',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                        'quantity_received' => '5.0000',
                        'unit_cost' => '60000.0000',
                        'batch_number' => 'LOT-ING-001',
                        'expiry_date' => '2027-06-30',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->assertInstanceOf(GoodsReceipt::class, $receipt);
        $this->assertSame(GoodsReceiptStatusEnum::Draft, $receipt->status);
        $this->assertCount(2, $receipt->items);
        $this->assertSame('550000.0000', $receipt->calculateTotalValue());

        // Verifikasi saldo belum berubah saat masih draft
        $prodBalance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) ($prodBalance->quantity ?? 0), '0', 4));

        // 2. Update Draft
        $updatedReceipt = $this->service->updateDraft($receipt, [
            'notes' => 'Draft revisi kuantitas',
            'items' => [
                [
                    'item_type' => 'product',
                    'product_id' => (int) $this->productA->id,
                    'quantity_received' => '15.0000',
                    'unit_cost' => '24000.0000',
                ],
            ],
        ], (int) $this->userAOwner->id);

        $this->assertCount(1, $updatedReceipt->items);
        $this->assertSame('15.0000', (string) $updatedReceipt->items->first()->quantity_received);
        $this->assertSame('360000.0000', $updatedReceipt->calculateTotalValue());

        // 3. Cancel Draft
        $cancelledReceipt = $this->service->cancelDraft($updatedReceipt, (int) $this->userAOwner->id);
        $this->assertSame(GoodsReceiptStatusEnum::Cancelled, $cancelledReceipt->status);
        $this->assertTrue($cancelledReceipt->isCancelled());

        // Verifikasi tidak bisa diposting jika status CANCELLED
        $this->expectException(DomainException::class);
        $this->service->postReceipt($cancelledReceipt, (int) $this->userAOwner->id);
    }

    public function test_post_receipt_updates_stock_balances_ledger_movements_and_wac(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Berikan saldo awal untuk Product: 10 unit @ 20.000 (total nilai 200.000)
        $this->ledgerService->recordMovement(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id,
            '10.0000',
            StockMovementType::Adjustment,
            '20000.0000',
            'Opening Stock'
        );

        // Buat dan Post Goods Receipt:
        // Masuk 10 unit @ 30.000 (total nilai 300.000)
        // Nilai total baru: 200.000 + 300.000 = 500.000
        // Total kuantitas baru: 10 + 10 = 20 unit
        // WAC baru: 500.000 / 20 = 25.000
        $receipt = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'supplier_reference' => 'Supplier Utama',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '10.0000',
                        'unit_cost' => '30000.0000',
                        'batch_number' => 'BATCH-TEST-2026',
                        'expiry_date' => '2028-01-01',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                        'quantity_received' => '20.0000',
                        'unit_cost' => '45000.0000',
                        'batch_number' => 'ING-BATCH-88',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $postedReceipt = $this->service->postReceipt($receipt, (int) $this->userAOwner->id);

        $this->assertSame(GoodsReceiptStatusEnum::Posted, $postedReceipt->status);
        $this->assertNotNull($postedReceipt->posted_at);
        $this->assertSame((int) $this->userAOwner->id, (int) $postedReceipt->posted_by);

        // Verifikasi Product Balance & WAC
        $prodBalance = ProductStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->firstOrFail();

        $this->assertSame(0, bccomp((string) $prodBalance->quantity, '20.0000', 4));
        $this->assertSame(0, bccomp((string) $prodBalance->average_unit_cost, '25000.0000', 4));
        $this->assertSame(0, bccomp((string) $prodBalance->last_unit_cost, '30000.0000', 4));

        // Verifikasi Product Movement Ledger
        $prodMovement = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->where('type', StockMovementType::GoodsReceipt->value)
            ->firstOrFail();

        $this->assertEquals(10, $prodMovement->quantity);
        $this->assertSame(0, bccomp((string) $prodMovement->unit_cost, '30000.0000', 4));
        $this->assertStringContainsString('BATCH-TEST-2026', $prodMovement->reason);

        // Verifikasi Ingredient Balance
        $ingBalance = IngredientStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('ingredient_id', $this->ingredientA->id)
            ->firstOrFail();

        $this->assertSame(0, bccomp((string) $ingBalance->quantity, '20.0000', 4));
        $this->assertSame(0, bccomp((string) $ingBalance->average_unit_cost, '45000.0000', 4));
        $this->assertSame(0, bccomp((string) $ingBalance->last_unit_cost, '45000.0000', 4));

        // Verifikasi Ingredient Movement Ledger
        $ingMovement = IngredientStockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('ingredient_id', $this->ingredientA->id)
            ->where('type', IngredientStockMovementTypeEnum::GoodsReceipt->value)
            ->firstOrFail();

        $this->assertSame(0, bccomp((string) $ingMovement->quantity, '20.0000', 4));
        $this->assertSame(0, bccomp((string) $ingMovement->unit_cost, '45000.0000', 4));
        $this->assertStringContainsString('ING-BATCH-88', $ingMovement->reason);
    }

    public function test_reverse_receipt_reduces_stock_and_records_counter_entries(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $receipt = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '8.0000',
                        'unit_cost' => '50000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->service->postReceipt($receipt, (int) $this->userAOwner->id);

        // Lakukan Reversal
        $reversedReceipt = $this->service->reverseReceipt(
            $receipt,
            (int) $this->userAOwner->id,
            'Salah input nomor faktur dan kuantitas'
        );

        $this->assertSame(GoodsReceiptStatusEnum::Reversed, $reversedReceipt->status);
        $this->assertNotNull($reversedReceipt->reversed_at);
        $this->assertSame('Salah input nomor faktur dan kuantitas', $reversedReceipt->reversal_reason);

        // Verifikasi saldo kembali menjadi 0
        $balance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) ($balance->quantity ?? 0), '0', 4));

        // Verifikasi counter movement tercatat
        $reversalMovement = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->where('type', StockMovementType::GoodsReceiptReversal->value)
            ->firstOrFail();

        $this->assertEquals(-8, $reversalMovement->quantity);
        $this->assertStringContainsString('Salah input nomor faktur', $reversalMovement->reason);
    }
}
