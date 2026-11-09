<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurant;

use App\Enums\KitchenStation;
use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Livewire\Kds\Board;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Settings\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class KdsStationFilterTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $cookUser;
    private Order $paidOrder;
    private OrderItem $kitchenItem;
    private OrderItem $barItem;
    private OrderItem $dessertItem;
    private OrderItem $overrideBarItem;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cook',
            'permissions' => ['*'],
        ]);

        $this->cookUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        // 1. Categories with KitchenStation Enum
        $catKitchen = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kuliner Tradisional Nusantara',
            'kitchen_station' => KitchenStation::Kitchen,
        ]);

        $catBar = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman Rempah & Jamu',
            'kitchen_station' => KitchenStation::Bar,
        ]);

        $catDessert = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kue Basah & Manisan',
            'kitchen_station' => KitchenStation::Dessert,
        ]);

        // 2. Products
        $prodNusantara = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catKitchen->id,
            'product_name' => 'Sop Buntut Bakar Madu',
            'product_code' => 'KDS-001',
            'product_price' => 85000,
            'product_type' => 'standard',
            'is_active' => true,
            'kitchen_station' => null, // Inherit from Category (Kitchen)
        ]);

        $prodBar = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catBar->id,
            'product_name' => 'Es Cendol Durian Segar',
            'product_code' => 'KDS-002',
            'product_price' => 28000,
            'product_type' => 'standard',
            'is_active' => true,
            'kitchen_station' => null, // Inherit from Category (Bar)
        ]);

        $prodDessert = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catDessert->id,
            'product_name' => 'Pisang Goreng Keju Aren',
            'product_code' => 'KDS-003',
            'product_price' => 22000,
            'product_type' => 'standard',
            'is_active' => true,
            'kitchen_station' => null, // Inherit from Category (Dessert)
        ]);

        $prodOverride = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catKitchen->id, // Category is Kitchen, but product is overridden to Bar!
            'product_name' => 'Spesial Mocktail Herbal',
            'product_code' => 'KDS-004',
            'product_price' => 35000,
            'product_type' => 'standard',
            'is_active' => true,
            'kitchen_station' => KitchenStation::Bar, // Explicit override
        ]);

        // 3. Paid Order
        $this->paidOrder = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cookUser->id,
            'order_code' => 'ORD-'.uniqid(),
            'order_date' => now(),
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 170000,
            'subtotal_amount' => 170000,
            'order_type' => 'dine_in',
            'idempotency_key' => 'kds-order-'.uniqid(),
        ]);

        $this->kitchenItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->paidOrder->id,
            'product_id' => $prodNusantara->id,
            'qty' => 1,
            'order_price' => 85000,
            'order_subtotal' => 85000,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);

        $this->barItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->paidOrder->id,
            'product_id' => $prodBar->id,
            'qty' => 1,
            'order_price' => 28000,
            'order_subtotal' => 28000,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);

        $this->dessertItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->paidOrder->id,
            'product_id' => $prodDessert->id,
            'qty' => 1,
            'order_price' => 22000,
            'order_subtotal' => 22000,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);

        $this->overrideBarItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->paidOrder->id,
            'product_id' => $prodOverride->id,
            'qty' => 1,
            'order_price' => 35000,
            'order_subtotal' => 35000,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);
    }

    public function test_all_station_filter_shows_all_paid_items(): void
    {
        Livewire::actingAs($this->cookUser)
            ->test(Board::class)
            ->assertSet('stationFilter', 'all')
            ->assertSee('Sop Buntut Bakar Madu')
            ->assertSee('Es Cendol Durian Segar')
            ->assertSee('Pisang Goreng Keju Aren')
            ->assertSee('Spesial Mocktail Herbal');
    }

    public function test_kitchen_filter_shows_only_kitchen_items_and_excludes_bar_and_dessert(): void
    {
        Livewire::actingAs($this->cookUser)
            ->test(Board::class)
            ->call('setStationFilter', 'kitchen')
            ->assertSee('Sop Buntut Bakar Madu')
            ->assertDontSee('Es Cendol Durian Segar')
            ->assertDontSee('Pisang Goreng Keju Aren')
            ->assertDontSee('Spesial Mocktail Herbal');
    }

    public function test_bar_filter_includes_bar_category_and_overridden_product(): void
    {
        Livewire::actingAs($this->cookUser)
            ->test(Board::class)
            ->call('setStationFilter', 'bar')
            ->assertSee('Es Cendol Durian Segar')
            ->assertSee('Spesial Mocktail Herbal')
            ->assertDontSee('Sop Buntut Bakar Madu')
            ->assertDontSee('Pisang Goreng Keju Aren');
    }

    public function test_dessert_filter_shows_only_dessert_station(): void
    {
        Livewire::actingAs($this->cookUser)
            ->test(Board::class)
            ->call('setStationFilter', 'dessert')
            ->assertSee('Pisang Goreng Keju Aren')
            ->assertDontSee('Sop Buntut Bakar Madu')
            ->assertDontSee('Es Cendol Durian Segar')
            ->assertDontSee('Spesial Mocktail Herbal');
    }

    public function test_unpaid_order_items_never_display_in_kds(): void
    {
        $unpaidOrder = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cookUser->id,
            'order_code' => 'ORD-UNPAID-'.uniqid(),
            'order_date' => now(),
            'order_status' => OrderStatus::Pending->value, // UNPAID
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_type' => 'dine_in',
            'idempotency_key' => 'unpaid-order-'.uniqid(),
        ]);

        $unpaidItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $unpaidOrder->id,
            'product_id' => $this->kitchenItem->product_id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);

        Livewire::actingAs($this->cookUser)
            ->test(Board::class)
            ->assertSet('stationFilter', 'all');

        $this->assertDatabaseHas('order_items', ['id' => $unpaidItem->id]);
    }
}
