<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockAdjustmentReasonEnum;
use App\Models\Ingredient;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockAdjustmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockAdjustmentService $service;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Bahan Baku Uji Otorisasi',
                'sku' => 'ING-AUTH-01',
                'unit' => 'kg',
                'cost_per_unit' => '12000.0000',
            ]);
        });

        $this->service = app(StockAdjustmentService::class);
    }

    public function test_tenant_a_cannot_view_or_manipulate_tenant_b_adjustment(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);

        $adjustmentB = $this->service->createDraft(
            (int) $this->tenantB->id,
            (int) $this->storeB->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::Damaged,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productB->id,
                        'direction' => 'OUT',
                        'quantity' => '2.0000',
                    ],
                ],
            ],
            (int) $this->userBBarista->id
        );

        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Query default ScopedToTenant tidak boleh menemukan adjustmentB
        $found = StockAdjustment::where('id', $adjustmentB->id)->first();
        $this->assertNull($found);

        // Policy check harus menolak User Tenant A
        $this->actingAs($this->userAOwner);
        $this->assertFalse(Gate::allows('view', $adjustmentB));
        $this->assertFalse(Gate::allows('update', $adjustmentB));
        $this->assertFalse(Gate::allows('cancel', $adjustmentB));
        $this->assertFalse(Gate::allows('post', $adjustmentB));
    }

    public function test_cannot_create_stock_adjustment_with_items_from_different_tenant(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Product ID {$this->productB->id} tidak ditemukan pada tenant {$this->tenantA->id}");

        $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'reason_code' => StockAdjustmentReasonEnum::Damaged,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productB->id,
                        'direction' => 'OUT',
                        'quantity' => '1.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );
    }

    public function test_branch_authorization_prevents_unauthorized_store_access(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $invRole = Role::withoutTenantScope()->firstOrCreate(
            ['name' => 'Inventory', 'tenant_id' => $this->tenantA->id],
            ['is_active' => true]
        );

        $staffUser = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $invRole->id,
        ]);
        $staffUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->actingAs($staffUser);

        $this->assertTrue(Gate::allows('create', [StockAdjustment::class, (int) $this->storeA->id]));
        $this->assertFalse(Gate::allows('create', [StockAdjustment::class, (int) $this->storeA2->id]));
    }

    public function test_database_triggers_strictly_enforce_xor_invariant(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

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
                        'quantity' => '1.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('INV-M4-02');

        // Direct DB insert: mengisi product_id dan ingredient_id bersamaan
        DB::table('stock_adjustment_items')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'stock_adjustment_id' => $adjustment->id,
            'item_type' => 'product',
            'product_id' => $this->productA->id,
            'ingredient_id' => $this->ingredientA->id,
            'direction' => 'IN',
            'quantity' => '5.0000',
            'unit_cost_snapshot' => '10000.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
