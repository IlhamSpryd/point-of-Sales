<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\SupplierReturnStatusEnum;
use App\Livewire\Inventory\SupplierReturnManager;
use App\Models\Ingredient;
use App\Models\SupplierReturn;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\SupplierReturnService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class SupplierReturnTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected SupplierReturnService $service;

    protected InventoryLedgerService $ledgerService;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kopi Arabika Toraja',
                'ingredient_code' => 'ING-TRJ-01',
                'unit' => 'kg',
                'cost_per_unit' => '65000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(SupplierReturnService::class);
    }

    public function test_can_create_update_and_cancel_supplier_return_draft(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $draft = $this->service->createDraft(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            data: [
                'supplier_name' => 'PT Mitra Sejahtera',
                'supplier_reference' => 'SJ-2026-001',
                'reason' => 'Barang mendekati tanggal kedaluwarsa',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->productA->id,
                        'quantity' => '5.0000',
                        'unit_cost' => '15000.0000',
                        'reason' => 'Kemasan rusak',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => $this->ingredientA->id,
                        'quantity' => '2.5000',
                        'unit_cost' => '65000.0000',
                        'reason' => 'Kualitas aroma menurun',
                    ],
                ],
            ],
            userId: $this->userAOwner->id
        );

        $this->assertSame(SupplierReturnStatusEnum::Draft, $draft->status);
        $this->assertCount(2, $draft->items);

        // Update draft
        $updated = $this->service->updateDraft(
            return: $draft,
            data: [
                'supplier_name' => 'PT Mitra Sejahtera Sentosa',
                'notes' => 'Telah dikonfirmasi via WA dengan PIC Supplier',
            ],
            userId: $this->userAOwner->id
        );

        $this->assertSame('PT Mitra Sejahtera Sentosa', $updated->supplier_name);
        $this->assertSame('Telah dikonfirmasi via WA dengan PIC Supplier', $updated->notes);

        // Cancel draft
        $cancelled = $this->service->cancelDraft(
            return: $updated,
            userId: $this->userAOwner->id,
            reason: 'Supplier bersedia memberikan diskon pengganti tanpa retur fisik'
        );

        $this->assertSame(SupplierReturnStatusEnum::Cancelled, $cancelled->status);
    }

    public function test_post_supplier_return_deducts_product_and_ingredient_stocks_with_non_negative_invariants(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Seed initial balance for product and ingredient
        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::GoodsReceipt,
            unitCost: '10000.0000',
            reason: 'Initial Goods Receipt for Test'
        );

        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'ingredient',
            itemId: $this->ingredientA->id,
            quantityDelta: '15.0000',
            type: IngredientStockMovementTypeEnum::GoodsReceipt,
            unitCost: '60000.0000',
            reason: 'Initial Goods Receipt for Ingredient'
        );

        // Create draft return
        $draft = $this->service->createDraft(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            data: [
                'supplier_name' => 'PT Sumber Kopi Makmur',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->productA->id,
                        'quantity' => '6.0000',
                        'unit_cost' => '10000.0000',
                    ],
                    [
                        'item_type' => 'ingredient',
                        'ingredient_id' => $this->ingredientA->id,
                        'quantity' => '4.0000',
                        'unit_cost' => '60000.0000',
                    ],
                ],
            ],
            userId: $this->userAOwner->id
        );

        // Post return
        $posted = $this->service->postReturn($draft, $this->userAOwner->id);
        $this->assertSame(SupplierReturnStatusEnum::Posted, $posted->status);

        // Check balances: product 20 - 6 = 14, ingredient 15 - 4 = 11
        $prodBalance = $this->ledgerService->getBalance($this->tenantA->id, $this->storeA->id, 'product', $this->productA->id);
        $this->assertNotNull($prodBalance);
        $this->assertSame(0, bccomp((string) $prodBalance->quantity, '14.0000', 4));
        $this->assertSame(0, bccomp((string) $prodBalance->average_unit_cost, '10000.0000', 4));

        $ingBalance = $this->ledgerService->getBalance($this->tenantA->id, $this->storeA->id, 'ingredient', $this->ingredientA->id);
        $this->assertNotNull($ingBalance);
        $this->assertSame(0, bccomp((string) $ingBalance->quantity, '11.0000', 4));
        $this->assertSame(0, bccomp((string) $ingBalance->average_unit_cost, '60000.0000', 4));

        // Verify activity log recorded
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'inventory.supplier_return.posted',
            'subject_id' => $posted->id,
        ]);
    }

    public function test_post_supplier_return_rejects_when_quantity_exceeds_available_stock_strict_non_negative(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Seed product balance with only 5 units
        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $this->productA->id,
            quantityDelta: '5.0000',
            type: StockMovementType::GoodsReceipt,
            unitCost: '12000.0000',
            reason: 'Seed 5 units'
        );

        // Attempt to return 10 units
        $draft = $this->service->createDraft(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            data: [
                'supplier_name' => 'Supplier Overdraft',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->productA->id,
                        'quantity' => '10.0000',
                        'unit_cost' => '12000.0000',
                    ],
                ],
            ],
            userId: $this->userAOwner->id
        );

        $this->expectException(ValidationException::class);

        try {
            $this->service->postReturn($draft, $this->userAOwner->id);
        } finally {
            // Verify status remained draft and balance remained intact
            $draft->refresh();
            $this->assertSame(SupplierReturnStatusEnum::Draft, $draft->status);

            $balance = $this->ledgerService->getBalance($this->tenantA->id, $this->storeA->id, 'product', $this->productA->id);
            $this->assertSame(0, bccomp((string) $balance->quantity, '5.0000', 4));
        }
    }

    public function test_reverse_posted_supplier_return_restores_deducted_stock(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Seed product balance with 10 units
        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $this->productA->id,
            quantityDelta: '10.0000',
            type: StockMovementType::GoodsReceipt,
            unitCost: '15000.0000',
            reason: 'Seed 10 units'
        );

        $draft = $this->service->createDraft(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            data: [
                'supplier_name' => 'Supplier Reversal Test',
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->productA->id,
                        'quantity' => '4.0000',
                        'unit_cost' => '15000.0000',
                    ],
                ],
            ],
            userId: $this->userAOwner->id
        );

        $posted = $this->service->postReturn($draft, $this->userAOwner->id);
        $this->assertSame(SupplierReturnStatusEnum::Posted, $posted->status);

        $midBalance = $this->ledgerService->getBalance($this->tenantA->id, $this->storeA->id, 'product', $this->productA->id);
        $this->assertSame(0, bccomp((string) $midBalance->quantity, '6.0000', 4));

        // Reverse the posted return
        $reversed = $this->service->reverseReturn($posted, $this->userAOwner->id, 'Supplier menolak retur karena kemasan sudah dibuka segelnya');
        $this->assertSame(SupplierReturnStatusEnum::Reversed, $reversed->status);

        // Stock restored back to 10
        $finalBalance = $this->ledgerService->getBalance($this->tenantA->id, $this->storeA->id, 'product', $this->productA->id);
        $this->assertSame(0, bccomp((string) $finalBalance->quantity, '10.0000', 4));

        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'inventory.supplier_return.reversed',
            'subject_id' => $reversed->id,
        ]);
    }

    public function test_cannot_reverse_draft_or_cancelled_supplier_return(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $draft = $this->service->createDraft(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $this->productA->id,
                        'quantity' => '1.0000',
                        'unit_cost' => '1000.0000',
                    ],
                ],
            ],
            userId: $this->userAOwner->id
        );

        $this->expectException(DomainException::class);
        $this->service->reverseReturn($draft, $this->userAOwner->id, 'Coba reverse draft');
    }

    public function test_xor_trigger_prevents_corrupt_items_with_both_or_neither_product_and_ingredient(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $return = SupplierReturn::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'return_number' => 'SR-XOR-TEST-001',
            'idempotency_key' => 'xor-test-key-01',
            'status' => SupplierReturnStatusEnum::Draft,
            'returned_at' => now(),
            'created_by' => $this->userAOwner->id,
        ]);

        // 1. Both product_id and ingredient_id populated -> trigger throws
        $bothFailed = false;
        try {
            DB::table('supplier_return_items')->insert([
                'tenant_id' => $this->tenantA->id,
                'supplier_return_id' => $return->id,
                'item_type' => 'product',
                'product_id' => $this->productA->id,
                'ingredient_id' => $this->ingredientA->id,
                'quantity' => '1.0000',
                'unit_cost' => '1000.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $bothFailed = true;
            $this->assertStringContainsString('XOR', $e->getMessage());
        }
        $this->assertTrue($bothFailed, 'Expected XOR trigger to reject item with both product and ingredient set.');

        // 2. Neither product_id nor ingredient_id populated -> trigger throws
        $neitherFailed = false;
        try {
            DB::table('supplier_return_items')->insert([
                'tenant_id' => $this->tenantA->id,
                'supplier_return_id' => $return->id,
                'item_type' => 'product',
                'product_id' => null,
                'ingredient_id' => null,
                'quantity' => '1.0000',
                'unit_cost' => '1000.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $neitherFailed = true;
            $this->assertStringContainsString('XOR', $e->getMessage());
        }
        $this->assertTrue($neitherFailed, 'Expected XOR trigger to reject item with neither product nor ingredient set.');
    }

    public function test_rbac_and_store_isolation_for_supplier_return(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $return = SupplierReturn::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'return_number' => 'SR-RBAC-001',
            'idempotency_key' => 'sr-rbac-001-key',
            'status' => SupplierReturnStatusEnum::Draft,
            'returned_at' => now(),
            'created_by' => $this->userAOwner->id,
        ]);

        // Barista B in Tenant B should be denied viewAny and view
        $this->assertFalse(Gate::forUser($this->userBBarista)->allows('viewAny', SupplierReturn::class));
        $this->assertFalse(Gate::forUser($this->userBBarista)->allows('view', $return));
        $this->assertFalse(Gate::forUser($this->userBBarista)->allows('create', [SupplierReturn::class, $this->storeA->id]));

        // Owner A should be allowed
        $this->assertTrue(Gate::forUser($this->userAOwner)->allows('viewAny', SupplierReturn::class));
        $this->assertTrue(Gate::forUser($this->userAOwner)->allows('view', $return));
        $this->assertTrue(Gate::forUser($this->userAOwner)->allows('create', [SupplierReturn::class, $this->storeA->id]));
    }

    public function test_livewire_supplier_return_manager_renders_and_operates(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Seed stock so posting succeeds
        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $this->productA->id,
            quantityDelta: '10.0000',
            type: StockMovementType::GoodsReceipt,
            unitCost: '15000.0000',
            reason: 'Seed 10 for livewire test'
        );

        $this->actingAs($this->userAOwner)
            ->withSession(['active_store_id' => $this->storeA->id]);

        $component = Livewire::test(SupplierReturnManager::class)
            ->assertOk()
            ->call('openCreateModal')
            ->assertSet('showFormModal', true)
            ->set('supplier_name', 'Livewire Test Supplier')
            ->set('reason', 'Barang berlebih')
            ->set('items.0.item_type', 'product')
            ->set('items.0.product_id', $this->productA->id)
            ->set('items.0.quantity', '2.0000')
            ->set('items.0.unit_cost', '15000.0000')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $return = SupplierReturn::where('tenant_id', $this->tenantA->id)
            ->where('supplier_name', 'Livewire Test Supplier')
            ->firstOrFail();

        $this->assertSame(SupplierReturnStatusEnum::Draft, $return->status);

        // Now post it via Livewire
        $component->call('postReturn', $return->id)
            ->assertHasNoErrors();

        $return->refresh();
        $this->assertSame(SupplierReturnStatusEnum::Posted, $return->status);

        // Now reverse it via Livewire
        $component->call('openReverseModal', $return->id)
            ->assertSet('showReverseModal', true)
            ->set('reversal_reason', 'Pembatalan pengembalian oleh manajer')
            ->call('submitReverse')
            ->assertHasNoErrors()
            ->assertSet('showReverseModal', false);

        $return->refresh();
        $this->assertSame(SupplierReturnStatusEnum::Reversed, $return->status);
    }
}
