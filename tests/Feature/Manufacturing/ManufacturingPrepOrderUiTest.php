<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Core\Context\TenantContext;
use App\Livewire\Manufacturing\ManufacturingOrderManager;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ManufacturingPrepOrderUiTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    private Product $product;

    private Ingredient $prepIngredient;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Central Kitchen Group '.uniqid(),
            'code' => 'CKG-'.strtoupper(Str::random(4)),
            'slug' => 'ckg-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Commissary Kitchen Tangerang',
            'code' => 'CK-'.substr(uniqid(), 0, 8),
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kitchen Manager',
            'permissions' => ['*'],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'name' => 'Chef Marco',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pastry',
            'slug' => 'pastry-'.uniqid(),
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Croissant Butter',
            'product_name' => 'Croissant Butter',
            'sku' => 'CR-'.Str::random(5),
            'price' => '28000.00',
            'is_active' => true,
        ]);

        $this->prepIngredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Croissant Dough Sheet (Prep)',
            'unit' => 'KG',
            'current_stock' => '0.0000',
            'cost_per_unit' => '45000.00',
            'reorder_level' => '10.0000',
            'is_active' => true,
        ]);
    }

    public function test_can_create_semi_finished_kitchen_prep_order_via_livewire(): void
    {
        Livewire::actingAs($this->user)
            ->test(ManufacturingOrderManager::class)
            ->assertStatus(200)
            ->set('orderType', 'prep')
            ->set('selectedIngredientId', $this->prepIngredient->id)
            ->set('plannedQuantity', '25.0000')
            ->set('notes', 'Batch persiapan adonan croissant pagi')
            ->call('createOrder')
            ->assertHasNoErrors()
            ->assertSee('Perintah Kerja Kitchen Prep');

        $order = ManufacturingOrder::where('tenant_id', $this->tenant->id)
            ->where('destination_type', 'ingredient')
            ->latest('id')
            ->first();

        $this->assertNotNull($order);
        $this->assertEquals($this->prepIngredient->id, $order->destination_ingredient_id);
        $this->assertEquals('25.0000', (string) $order->planned_quantity);
        $this->assertStringStartsWith('MO-PREP-', $order->order_number);
        $this->assertTrue($order->isPrepOrder());
    }

    public function test_prep_order_creation_rejects_foreign_tenant_ingredient(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Rival Bakery '.uniqid(),
            'slug' => 'rival-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($otherTenant->id);

        $foreignIngredient = Ingredient::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Secret Sauce',
            'unit' => 'L',
            'current_stock' => '5.0000',
            'cost_per_unit' => '10000.00',
            'reorder_level' => '1.0000',
            'is_active' => true,
        ]);

        $context->setTenantId($this->tenant->id);

        Livewire::actingAs($this->user)
            ->test(ManufacturingOrderManager::class)
            ->set('orderType', 'prep')
            ->set('selectedIngredientId', $foreignIngredient->id)
            ->set('plannedQuantity', '10.0000')
            ->call('createOrder')
            ->assertHasErrors(['selectedIngredientId']);
    }

    public function test_can_create_finished_product_order_via_livewire(): void
    {
        Livewire::actingAs($this->user)
            ->test(ManufacturingOrderManager::class)
            ->set('orderType', 'product')
            ->set('selectedProductId', $this->product->id)
            ->set('plannedQuantity', '50.0000')
            ->call('createOrder')
            ->assertHasNoErrors()
            ->assertSee('Manufacturing Order');

        $order = ManufacturingOrder::where('tenant_id', $this->tenant->id)
            ->where('destination_type', 'product')
            ->latest('id')
            ->first();

        $this->assertNotNull($order);
        $this->assertEquals($this->product->id, $order->product_id);
        $this->assertFalse($order->isPrepOrder());
    }
}
