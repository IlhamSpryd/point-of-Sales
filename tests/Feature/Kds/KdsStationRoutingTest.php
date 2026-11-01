<?php

declare(strict_types=1);

namespace Tests\Feature\Kds;

use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Livewire\Kds\Board;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class KdsStationRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store;

    protected User $chef;

    protected Product $foodProduct;

    protected Product $drinkProduct;

    protected OrderItem $foodItem;

    protected OrderItem $drinkItem;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'KDS Station Tenant',
            'slug' => 'kds-station-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'KDS Branch',
            'code' => 'KDS-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cook',
            'permissions' => ['*'],
        ]);

        $this->chef = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        $foodCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Hot Food & Kitchen',
        ]);

        $drinkCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Coffee & Beverages',
        ]);

        $this->foodProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $foodCategory->id,
            'product_name' => 'Beef Burger',
            'product_price' => 45000,
            'stock' => 10,
        ]);

        $this->drinkProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $drinkCategory->id,
            'product_name' => 'Iced Latte',
            'product_price' => 30000,
            'stock' => 10,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->chef->id,
            'order_code' => 'ORD-KDS-'.uniqid(),
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 75000,
            'order_amount' => 75000,
            'idempotency_key' => 'idemp-kds-'.uniqid(),
        ]);

        $this->foodItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->foodProduct->id,
            'qty' => 1,
            'order_price' => 45000,
            'order_subtotal' => 45000,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        $this->drinkItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->drinkProduct->id,
            'qty' => 1,
            'order_price' => 30000,
            'order_subtotal' => 30000,
            'preparation_status' => PreparationStatus::Pending,
        ]);
    }

    public function test_kds_board_station_filter_routes_items_correctly(): void
    {
        Livewire::actingAs($this->chef)
            ->test(Board::class)
            // 1. Default 'all' shows both items
            ->assertSet('stationFilter', 'all')
            ->assertSee('Beef Burger')
            ->assertSee('Iced Latte')
            // 2. Switch to 'kitchen': only food visible
            ->call('setStationFilter', 'kitchen')
            ->assertSet('stationFilter', 'kitchen')
            ->assertSee('Beef Burger')
            ->assertDontSee('Iced Latte')
            // 3. Switch to 'bar': only drinks visible
            ->call('setStationFilter', 'bar')
            ->assertSet('stationFilter', 'bar')
            ->assertSee('Iced Latte')
            ->assertDontSee('Beef Burger')
            // 4. Switch back to 'all'
            ->call('setStationFilter', 'all')
            ->assertSee('Beef Burger')
            ->assertSee('Iced Latte');
    }
}
