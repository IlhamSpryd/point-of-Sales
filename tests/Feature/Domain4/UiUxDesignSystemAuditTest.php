<?php

declare(strict_types=1);

namespace Tests\Feature\Domain4;

use App\Livewire\Kasir\CreateOrder;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class UiUxDesignSystemAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Role $kasirRole;
    protected User $cashierUser;
    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Domain 4 Tenant',
            'slug' => 'domain-4-tenant',
            'status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Domain 4 Store',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $this->kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'can_void_order'],
        ]);

        $this->cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->kasirRole->id,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cashierUser->id,
            'store_id' => $this->store->id,
        ]);

        Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Espresso Domain 4',
            'product_price' => 20000,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    public function test_cashier_can_increment_and_decrement_cart_line_quantity(): void
    {
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('addToCartDirectly', $this->product->id)
            ->assertCount('cart', 1)
            ->call('updateCartQty', 0, 1)
            ->assertSet('cart.0.qty', 2)
            ->call('updateCartQty', 0, -1)
            ->assertSet('cart.0.qty', 1)
            ->call('updateCartQty', 0, -1)
            ->assertCount('cart', 0);
    }

    public function test_modal_component_renders_accessible_dialog_roles(): void
    {
        $rendered = Blade::render(
            '<x-modal name="test-dialog" :show="true"><div>Modal Content</div></x-modal>'
        );

        $this->assertStringContainsString('role="dialog"', $rendered);
        $this->assertStringContainsString('aria-modal="true"', $rendered);
    }

    public function test_blade_views_contain_wcag_a11y_attributes(): void
    {
        $createOrderView = file_get_contents(resource_path('views/livewire/kasir/create-order.blade.php'));
        $orderHistoryView = file_get_contents(resource_path('views/livewire/kasir/order-history.blade.php'));

        // Catalog cards have keyboard activation listeners
        $this->assertStringContainsString('@keydown.enter=', $createOrderView);
        $this->assertStringContainsString('@keydown.space.prevent=', $createOrderView);
        $this->assertStringContainsString('role="button"', $createOrderView);
        $this->assertStringContainsString('tabindex="0"', $createOrderView);

        // Cart items have quantity update controls
        $this->assertStringContainsString('updateCartQty(', $createOrderView);

        // Modifier picker has dialog semantics and Escape key handling
        $this->assertStringContainsString('aria-labelledby="modifier-picker-title"', $createOrderView);
        $this->assertStringContainsString('@keydown.escape.window="$wire.closeModifierPicker()"', $createOrderView);

        // Order history has alertdialog for void and dialog for detail
        $this->assertStringContainsString('role="alertdialog"', $orderHistoryView);
        $this->assertStringContainsString('role="dialog"', $orderHistoryView);
        $this->assertStringContainsString('id="void-reason-input"', $orderHistoryView);
        $this->assertStringContainsString('@keydown.escape.stop.prevent=', $orderHistoryView);
    }
}
