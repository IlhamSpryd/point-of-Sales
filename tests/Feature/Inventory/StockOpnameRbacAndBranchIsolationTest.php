<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Ingredient;
use App\Models\Role;
use App\Models\StockOpname;
use App\Models\Store;
use App\Models\User;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockOpnameRbacAndBranchIsolationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockOpnameService $service;

    protected Ingredient $ingredientA;

    protected User $cashierUser;

    protected User $inventoryUser;

    protected User $managerStoreA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->setupTwoTenants();

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        Model::unguard();

        $this->ingredientA = Ingredient::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bahan Baku Uji RBAC',
            'ingredient_code' => 'ING-RBAC-01',
            'unit' => 'kg',
            'cost_per_unit' => '10000.0000',
        ]);

        $cashierRole = Role::withoutTenantScope()->firstOrCreate([
            'name' => 'Cashier',
            'tenant_id' => $this->tenantA->id,
        ], ['is_active' => 1]);

        $inventoryRole = Role::withoutTenantScope()->firstOrCreate([
            'name' => 'Inventory',
            'tenant_id' => $this->tenantA->id,
        ], ['is_active' => 1]);

        $managerRole = Role::withoutTenantScope()->firstOrCreate([
            'name' => 'Manager',
            'tenant_id' => $this->tenantA->id,
        ], ['is_active' => 1]);

        $this->cashierUser = User::create([
            'name' => 'Cashier A',
            'email' => 'cashierA_rbac@test.com',
            'password' => bcrypt('password'),
            'pin_hash' => bcrypt('123456'),
            'email_verified_at' => now(),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $cashierRole->id,
            'is_active' => 1,
        ]);
        $this->cashierUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->inventoryUser = User::create([
            'name' => 'Inventory A',
            'email' => 'inventoryA_rbac@test.com',
            'password' => bcrypt('password'),
            'pin_hash' => bcrypt('123456'),
            'email_verified_at' => now(),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $inventoryRole->id,
            'is_active' => 1,
        ]);
        $this->inventoryUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->managerStoreA = User::create([
            'name' => 'Manager Store A',
            'email' => 'managerA_rbac@test.com',
            'password' => bcrypt('password'),
            'pin_hash' => bcrypt('123456'),
            'email_verified_at' => now(),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $managerRole->id,
            'is_active' => 1,
        ]);
        $this->managerStoreA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        Model::reguard();

        $this->service = app(StockOpnameService::class);
    }

    public function test_cashier_cannot_create_count_or_approve_opname(): void
    {
        $this->actingAs($this->cashierUser);

        // Kasir ditolak untuk viewAny dan create
        $this->assertFalse(Gate::allows('viewAny', StockOpname::class));
        $this->assertFalse(Gate::allows('create', [StockOpname::class, (int) $this->storeA->id]));
    }

    public function test_inventory_role_cannot_approve_opname_segregation_of_duties(): void
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
            userId: (int) $this->inventoryUser->id
        );

        $opname = $this->service->startCounting($opname, (int) $this->inventoryUser->id);
        $item = $opname->items[0]->fresh();
        $this->service->recordCounts($opname, [$item->id => '10.0000'], (int) $this->inventoryUser->id);
        $opname = $this->service->submitForApproval($opname, (int) $this->inventoryUser->id);

        $this->actingAs($this->inventoryUser);

        // Petugas Inventory BISA view, BISA count, BISA submit...
        $this->assertTrue(Gate::allows('view', $opname));

        // ...NAMUN TIDAK BOLEH approve! (Segregation of Duties / OD-04)
        $this->assertFalse(Gate::allows('approve', $opname));

        // Manajer toko tersebut BOLEH approve
        $this->actingAs($this->managerStoreA);
        $this->assertTrue(Gate::allows('approve', $opname));
    }

    public function test_manager_of_branch_a_cannot_view_or_approve_branch_b_opname(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA2->id);

        // Buat opname di Cabang 2
        $opnameStoreA2 = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productA->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        // Manager Cabang 1 hanya memiliki akses ke Store A, bukan Store A2
        $this->actingAs($this->managerStoreA);

        $this->assertFalse(Gate::allows('view', $opnameStoreA2));
        $this->assertFalse(Gate::allows('approve', $opnameStoreA2));
    }

    public function test_cross_tenant_injection_fails_integrity_checks(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Product #{$this->productB->id} tidak valid untuk tenant ini.");

        $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productB->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );
    }

    public function test_database_xor_trigger_enforces_product_xor_ingredient(): void
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

        // Percobaan menyisipkan baris dengan kedua ID (product_id DAN ingredient_id)
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('INV-M4-02: stock_opname_items XOR invariant violated');

        DB::table('stock_opname_items')->insert([
            'tenant_id' => (int) $this->tenantA->id,
            'store_id' => (int) $this->storeA->id,
            'stock_opname_id' => (int) $opname->id,
            'item_type' => 'product',
            'product_id' => (int) $this->productA->id,
            'ingredient_id' => (int) $this->ingredientA->id, // MELANGGAR XOR!
            'snapshot_on_hand' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
