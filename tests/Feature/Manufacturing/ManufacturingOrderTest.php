<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Livewire\Manufacturing\ManufacturingOrderManager;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Models\ProductIngredient;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ManufacturingOrderTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Role $role;

    private User $user;

    private Product $product;

    private Ingredient $ingredient;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Industrial Bakery Group '.uniqid(),
            'code' => 'MFG-'.strtoupper(Str::random(4)),
            'slug' => 'mfg-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Production Plant Cikarang',
            'code' => 'PLT-'.substr(uniqid(), 0, 10),
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Production Plant Manager',
            'email' => 'plant.mgr.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
        ]);

        $category = \App\Models\Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'BAKE-'.substr(uniqid(), 0, 8),
            'category_name' => 'Bakery & Pastry',
            'is_active' => true,
        ]);

        // Master Finished Good Product
        $this->product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'BREAD-01-'.uniqid(),
            'product_name' => 'Artisan Sourdough Loaf 500g',
            'product_price' => '45000.00',
            'product_type' => 'standard',
            'status' => 'ACTIVE',
            'stock' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        // Master Raw Material / Ingredient
        $this->ingredient = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tepung Terigu Protein Tinggi (kg)',
            'unit' => 'kg',
            'current_stock' => 100.0000,
            'cost_per_unit' => 12000.00,
            'is_active' => true,
        ]);

        // Seed stock balance for raw material
        DB::table('ingredient_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'ingredient_id' => $this->ingredient->id,
            ],
            [
                'quantity' => 100.0000,
                'updated_at' => now(),
            ]
        );

        // Seed stock balance for finished good
        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $this->product->id,
            ],
            [
                'quantity' => 0.0000,
                'updated_at' => now(),
            ]
        );

        // Recipe BOM: 1 Sourdough loaf requires 0.35 kg flour
        ProductIngredient::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity_required' => '0.3500',
        ]);
    }

    public function test_manufacturing_order_service_lifecycle_draft_start_complete(): void
    {
        $service = app(ManufacturingOrderService::class);

        // 1. Create MO
        $order = $service->createOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $this->product->id,
            plannedQuantity: '20.0000',
            notes: 'Batch roti pagi',
            userId: $this->user->id
        );

        $this->assertEquals('PLANNED', $order->status);
        $this->assertEquals('20.0000', (string) $order->planned_quantity);
        $this->assertDatabaseHas('manufacturing_orders', [
            'id' => $order->id,
            'tenant_id' => $this->tenant->id,
            'status' => 'PLANNED',
        ]);

        // 2. Start Production
        $order = $service->startProduction($order->id);
        $this->assertEquals('IN_PROGRESS', $order->status);

        // 3. Complete Production: Produces 20 loaves -> consumes 20 * 0.35 = 7.0000 kg flour
        $order = $service->completeProduction($order->id);
        $this->assertEquals('COMPLETED', $order->status);
        $this->assertEquals('20.0000', (string) $order->produced_quantity);
        $this->assertNotNull($order->completed_at);

        // 4. Verify Raw Material Deduction
        $ingredientStock = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $this->ingredient->id)
            ->value('quantity');

        // 100 - 7 = 93.0000
        $this->assertEquals(93.0000, (float) $ingredientStock);

        // 5. Verify Finished Good Increment
        $productStock = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');

        $this->assertEquals(20.0000, (float) $productStock);
    }

    public function test_manufacturing_order_can_be_cancelled(): void
    {
        $service = app(ManufacturingOrderService::class);

        $order = $service->createOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $this->product->id,
            plannedQuantity: '10.0000'
        );

        $order = $service->cancelOrder($order->id);
        $this->assertEquals('CANCELLED', $order->status);

        // Completing a cancelled order must throw exception
        $this->expectException(\RuntimeException::class);
        $service->completeProduction($order->id);
    }

    public function test_manufacturing_order_livewire_manager_component_renders_and_executes_actions(): void
    {
        Livewire::actingAs($this->user)
            ->test(ManufacturingOrderManager::class)
            ->assertStatus(200)
            ->assertSee('Discrete Manufacturing Orders (MO)')
            ->set('selectedProductId', $this->product->id)
            ->set('plannedQuantity', '15.0000')
            ->set('notes', 'Uji batch livewire')
            ->call('createOrder')
            ->assertHasNoErrors();

        $mo = ManufacturingOrder::where('tenant_id', $this->tenant->id)->latest('id')->first();
        $this->assertNotNull($mo);
        $this->assertEquals('15.0000', (string) $mo->planned_quantity);

        // Start Order via Livewire
        Livewire::actingAs($this->user)
            ->test(ManufacturingOrderManager::class)
            ->call('startOrder', $mo->id)
            ->assertHasNoErrors();

        $this->assertEquals('IN_PROGRESS', $mo->fresh()->status);

        // Complete Order via Livewire
        Livewire::actingAs($this->user)
            ->test(ManufacturingOrderManager::class)
            ->call('completeOrder', $mo->id)
            ->assertHasNoErrors();

        $this->assertEquals('COMPLETED', $mo->fresh()->status);
    }

    public function test_manufacturing_orders_enforce_tenant_scoping(): void
    {
        // 1. Create order for Tenant A while in Tenant A's context
        $moA = ManufacturingOrder::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_number' => 'MO-SECRET-A-999',
            'product_id' => $this->product->id,
            'planned_quantity' => '5.0000',
            'status' => 'PLANNED',
        ]);

        // 2. Setup Tenant B and switch context
        $tenantB = Tenant::create([
            'name' => 'Foreign Manufacturing Co '.uniqid(),
            'code' => 'MFG-B-'.strtoupper(Str::random(4)),
            'slug' => 'mfg-b-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($tenantB->id);

        $storeB = Store::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Store B',
            'code' => 'PLT-B-'.substr(uniqid(), 0, 10),
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($storeB->id);

        $roleB = Role::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $userB = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Plant Manager B',
            'email' => 'plant.b.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role_id' => $roleB->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'store_id' => $storeB->id,
        ]);

        Livewire::actingAs($userB)
            ->test(ManufacturingOrderManager::class)
            ->assertDontSee('MO-SECRET-A-999');
    }
}
