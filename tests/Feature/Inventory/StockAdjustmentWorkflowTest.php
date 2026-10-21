<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockAdjustmentReasonEnum;
use App\Enums\StockAdjustmentStatusEnum;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\IngredientStockBalance;
use App\Models\IngredientStockMovement;
use App\Models\ProductStockBalance;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockAdjustmentWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockAdjustmentService $service;

    protected InventoryLedgerService $ledgerService;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Susu Segar UHT',
                'sku' => 'ING-MILK-01',
                'unit' => 'liter',
                'cost_per_unit' => '18000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(StockAdjustmentService::class);
    }

    public function test_can_create_update_and_cancel_stock_adjustment_draft(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Create Draft
        $adjustment = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::Damaged,
                'notes' => 'Barang kemasan bocor saat pengiriman',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'direction' => 'OUT',
                        'quantity' => '3.0000',
                        'unit_cost_snapshot' => '15000.0000',
                        'reason_note' => 'Kaleng penyok dan bocor',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->assertInstanceOf(StockAdjustment::class, $adjustment);
        $this->assertSame(StockAdjustmentStatusEnum::Draft, $adjustment->status);
        $this->assertSame(StockAdjustmentReasonEnum::Damaged, $adjustment->reason_code);
        $this->assertCount(1, $adjustment->items);
        $this->assertSame('45000.0000', $adjustment->calculateTotalValue());

        // Verifikasi jejak audit pembuatan draft
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'inventory.stock_adjustment.created',
            'subject_id' => $adjustment->id,
        ]);

        // 2. Update Draft
        $updated = $this->service->updateDraft($adjustment, [
            'reason_code' => StockAdjustmentReasonEnum::Expired,
            'notes' => 'Ternyata barang sudah expired',
            'items' => [
                [
                    'item_type' => 'product',
                    'product_id' => (int) $this->productA->id,
                    'direction' => 'OUT',
                    'quantity' => '2.0000',
                    'unit_cost_snapshot' => '15000.0000',
                ],
            ],
        ], (int) $this->userAOwner->id);

        $this->assertSame(StockAdjustmentReasonEnum::Expired, $updated->reason_code);
        $this->assertSame('2.0000', (string) $updated->items->first()->quantity);

        // 3. Cancel Draft
        $cancelled = $this->service->cancelDraft($updated, (int) $this->userAOwner->id);
        $this->assertSame(StockAdjustmentStatusEnum::Cancelled, $cancelled->status);

        $this->expectException(DomainException::class);
        $this->service->postAdjustment($cancelled, (int) $this->userAOwner->id);
    }

    public function test_post_adjustment_in_increases_stock_and_records_audit(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Adjustment IN: Opening Stock produk & bahan baku
        $adjustment = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::OpeningStock,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'direction' => 'IN',
                        'quantity' => '25.0000',
                        'unit_cost_snapshot' => '20000.0000',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => (int) $this->ingredientA->id,
                        'direction' => 'IN',
                        'quantity' => '10.0000',
                        'unit_cost_snapshot' => '18000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $posted = $this->service->postAdjustment($adjustment, (int) $this->userAOwner->id);
        $this->assertSame(StockAdjustmentStatusEnum::Posted, $posted->status);
        $this->assertNotNull($posted->posted_at);

        // Verifikasi saldo bertambah
        $prodBalance = ProductStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp((string) $prodBalance->quantity, '25.0000', 4));

        $ingBalance = IngredientStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('ingredient_id', $this->ingredientA->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp((string) $ingBalance->quantity, '10.0000', 4));

        // Verifikasi movement ledger
        $prodMovement = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->where('type', StockMovementType::AdjustmentIn->value)
            ->firstOrFail();
        $this->assertEquals(25, $prodMovement->quantity);

        $ingMovement = IngredientStockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('ingredient_id', $this->ingredientA->id)
            ->where('type', IngredientStockMovementTypeEnum::AdjustmentIn->value)
            ->firstOrFail();
        $this->assertSame(0, bccomp((string) $ingMovement->quantity, '10.0000', 4));

        // Verifikasi Audit Trail tercatat di activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'inventory.stock_adjustment.posted',
            'subject_id' => $adjustment->id,
        ]);
    }

    public function test_post_adjustment_out_decreases_stock_when_sufficient(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Berikan saldo awal 15 unit
        $this->ledgerService->recordMovement(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id,
            '15.0000',
            StockMovementType::AdjustmentIn,
            '20000.0000',
            'Saldo Awal'
        );

        // Kurangi 4 unit karena Damaged
        $adjustment = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::Damaged,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'direction' => 'OUT',
                        'quantity' => '4.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->service->postAdjustment($adjustment, (int) $this->userAOwner->id);

        $balance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) $balance->quantity, '11.0000', 4));

        $movement = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->where('type', StockMovementType::AdjustmentOut->value)
            ->firstOrFail();
        $this->assertEquals(-4, $movement->quantity);
    }

    public function test_adjustment_out_strictly_rejected_if_causes_negative_stock(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Saldo saat ini 5 unit
        $this->ledgerService->recordMovement(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id,
            '5.0000',
            StockMovementType::AdjustmentIn,
            '20000.0000',
            'Saldo awal'
        );

        // Buat penyesuaian OUT sebesar 8 unit (melebihi saldo 5)
        $adjustment = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::Shrinkage,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'direction' => 'OUT',
                        'quantity' => '8.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        // Harus ditolak oleh Invariant OD-05
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('OD-05');

        try {
            $this->service->postAdjustment($adjustment, (int) $this->userAOwner->id);
        } finally {
            // Verifikasi saldo tetap 5 unit dan status dokumen tetap DRAFT
            $balance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
            $this->assertSame(0, bccomp((string) $balance->quantity, '5.0000', 4));

            $fresh = StockAdjustment::find($adjustment->id);
            $this->assertSame(StockAdjustmentStatusEnum::Draft, $fresh->status);
        }
    }

    public function test_post_adjustment_is_idempotent(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $adjustment = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::Found,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'direction' => 'IN',
                        'quantity' => '7.0000',
                        'unit_cost_snapshot' => '10000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $first = $this->service->postAdjustment($adjustment, (int) $this->userAOwner->id);
        $second = $this->service->postAdjustment($first, (int) $this->userAOwner->id);

        $this->assertSame(StockAdjustmentStatusEnum::Posted, $second->status);

        $balance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) $balance->quantity, '7.0000', 4));

        $count = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->count();
        $this->assertSame(1, $count);
    }
}
