<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class GoodsReceiptTenantBranchIsolationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected GoodsReceiptService $service;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Bahan Uji XOR',
                'sku' => 'ING-XOR-01',
                'unit' => 'kg',
                'cost_per_unit' => '10000.0000',
            ]);
        });

        $this->service = app(GoodsReceiptService::class);
    }

    public function test_tenant_a_cannot_view_or_manipulate_tenant_b_goods_receipt(): void
    {
        // 1. Buat GRN di Tenant B
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);

        $receiptB = $this->service->createDraft(
            (int) $this->tenantB->id,
            (int) $this->storeB->id,
            [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productB->id,
                        'quantity_received' => '10.0000',
                        'unit_cost' => '20000.0000',
                    ],
                ],
            ],
            (int) $this->userBBarista->id
        );

        // 2. Switch ke konteks Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Query default scopedToTenant tidak boleh menemukan receiptB
        $found = GoodsReceipt::where('id', $receiptB->id)->first();
        $this->assertNull($found);

        // Policy check harus menolak User Tenant A mengakses receiptB
        $this->actingAs($this->userAOwner);
        $this->assertFalse(Gate::allows('view', $receiptB));
        $this->assertFalse(Gate::allows('update', $receiptB));
        $this->assertFalse(Gate::allows('post', $receiptB));
        $this->assertFalse(Gate::allows('reverse', $receiptB));
    }

    public function test_cannot_create_goods_receipt_with_items_from_different_tenant(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Coba masukkan productB (milik Tenant B) ke draft Tenant A
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Product ID {$this->productB->id} tidak ditemukan pada tenant {$this->tenantA->id}");

        $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productB->id,
                        'quantity_received' => '5.0000',
                        'unit_cost' => '10000.0000',
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

        // Buat user staf inventori yang hanya di-assign ke storeA
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

        // Diizinkan di storeA
        $this->assertTrue(Gate::allows('create', [GoodsReceipt::class, (int) $this->storeA->id]));

        // Ditolak di storeA2 (cabang kedua tenant A) karena user tidak di-assign ke storeA2
        $this->assertFalse(Gate::allows('create', [GoodsReceipt::class, (int) $this->storeA2->id]));
    }

    public function test_database_triggers_strictly_enforce_xor_invariant(): void
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
                        'quantity_received' => '2.0000',
                        'unit_cost' => '10000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        // 1. Direct DB insertion melanggar XOR: product_id DAN ingredient_id keduanya diisi
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('INV-M4-02');

        DB::table('goods_receipt_items')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'goods_receipt_id' => $receipt->id,
            'item_type' => 'product',
            'product_id' => $this->productA->id,
            'ingredient_id' => $this->ingredientA->id, // Keduanya diisi!
            'quantity_received' => '5.0000',
            'unit_cost' => '10000.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_triggers_enforce_xor_invariant_when_both_null(): void
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
                        'quantity_received' => '2.0000',
                        'unit_cost' => '10000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        // Direct DB insertion: keduanya NULL
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('INV-M4-02');

        DB::table('goods_receipt_items')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'goods_receipt_id' => $receipt->id,
            'item_type' => 'product',
            'product_id' => null,
            'ingredient_id' => null, // Keduanya NULL!
            'quantity_received' => '5.0000',
            'unit_cost' => '10000.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
