<?php

declare(strict_types=1);

namespace Tests\Feature\Kds;

use App\Core\Context\TenantContext;
use App\Enums\KitchenStation;
use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Events\KdsItemUpdated;
use App\Events\KdsOrderCreated;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class KdsRealtimeBroadcastSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Store $otherStore;

    private User $authorizedCook;

    private User $tenantBIntruder;

    private User $unauthorizedCashier;

    private OrderItem $orderItem;

    private Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            // Tenant A
            $this->tenant = Tenant::create([
                'name' => 'KDS Realtime Bistro',
                'slug' => 'kds-realtime-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->store = Store::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Kitchen Store 1',
                'code' => 'ST1-'.uniqid(),
            ]);

            $this->otherStore = Store::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Branch Store 2',
                'code' => 'ST2-'.uniqid(),
            ]);

            $cookRole = Role::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Cook',
                'display_name' => 'Kitchen Cook',
                'permissions' => ['kds.access', 'kds.view'],
            ]);

            $cashierRole = Role::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Cashier',
                'display_name' => 'Front Cashier',
                'permissions' => ['pos.checkout'],
            ]);

            $this->authorizedCook = User::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Authorized Cook',
                'email' => 'cook.'.uniqid().'@test.com',
                'password' => bcrypt('secret'),
                'role_id' => $cookRole->id,
                'is_active' => true,
            ]);
            $this->authorizedCook->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

            $this->unauthorizedCashier = User::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Cashier Without KDS',
                'email' => 'cashier.'.uniqid().'@test.com',
                'password' => bcrypt('secret'),
                'role_id' => $cashierRole->id,
                'is_active' => true,
            ]);
            $this->unauthorizedCashier->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

            // Tenant B Intruder
            $tenantB = Tenant::create([
                'name' => 'Hostile Tenant B',
                'slug' => 'hostile-'.uniqid(),
                'status' => 'active',
            ]);

            $intruderRole = Role::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Cook',
                'display_name' => 'Hostile Cook',
                'permissions' => ['kds.access'],
            ]);

            $this->tenantBIntruder = User::withoutGlobalScopes()->create([
                'tenant_id' => $tenantB->id,
                'name' => 'Eavesdropping Intruder',
                'email' => 'intruder.'.uniqid().'@hostile.com',
                'password' => bcrypt('secret'),
                'role_id' => $intruderRole->id,
                'is_active' => true,
            ]);

            // Sample Category & Product
            $category = Category::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Entrees',
                'slug' => 'entrees-'.uniqid(),
                'kitchen_station' => KitchenStation::Kitchen,
            ]);

            $this->product = Product::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $category->id,
                'product_name' => 'Grilled Salmon',
                'product_code' => 'SALMON-'.uniqid(),
                'product_price' => 75000.00,
                'product_type' => 'standard',
                'kitchen_station' => KitchenStation::Kitchen,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_code' => 'ORD-'.uniqid(),
            'order_date' => now(),
            'user_id' => $this->authorizedCook->id,
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 75000.00,
            'subtotal_amount' => 75000.00,
            'order_type' => 'dine_in',
            'idempotency_key' => 'kds-order-'.uniqid(),
        ]);

        $this->orderItem = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 75000.00,
            'order_subtotal' => 75000.00,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);
    }

    public function test_kds_item_updated_broadcasts_on_exact_tenant_and_store_private_channel(): void
    {
        $event = new KdsItemUpdated($this->orderItem, 'claimed');

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-kds.tenant.{$this->tenant->id}.store.{$this->store->id}", $channels[0]->name);
        $this->assertSame('kds.item.updated', $event->broadcastAs());
    }

    public function test_kds_item_updated_payload_contains_only_scalar_values_without_eloquent_instances(): void
    {
        $event = new KdsItemUpdated($this->orderItem, 'ready');
        $payload = $event->broadcastWith();

        $this->assertIsArray($payload);
        $this->assertSame($this->orderItem->id, $payload['item_id']);
        $this->assertSame($this->orderItem->order_id, $payload['order_id']);
        $this->assertSame($this->tenant->id, $payload['tenant_id']);
        $this->assertSame($this->store->id, $payload['store_id']);
        $this->assertSame('ready', $payload['action']);
        $this->assertSame('kitchen', $payload['station']);
        $this->assertIsString($payload['updated_at']);

        // Assert strictly zero Eloquent Model instances in broadcast payload
        foreach ($payload as $key => $value) {
            $this->assertFalse(
                $value instanceof Model,
                "Broadcast payload key '{$key}' must not be an Eloquent model instance."
            );
        }
    }

    public function test_channel_authorization_allows_authorized_cook_and_strictly_denies_intruders(): void
    {
        $channels = Broadcast::getChannels();
        $this->assertArrayHasKey('kds.tenant.{tenantId}.store.{storeId}', $channels);

        $callback = $channels['kds.tenant.{tenantId}.store.{storeId}'];

        // 1. Authorized Cook in Tenant A, Store 1 MUST be granted access
        $this->assertTrue(
            $callback($this->authorizedCook, $this->tenant->id, $this->store->id),
            'Authorized Cook in Tenant A / Store 1 must be authorized'
        );

        // 2. Tenant B Intruder MUST be strictly DENIED (Fail-closed cross-tenant isolation)
        $this->assertFalse(
            $callback($this->tenantBIntruder, $this->tenant->id, $this->store->id),
            'Tenant B Intruder must fail-closed on Tenant A channel'
        );

        // 3. User with non-KDS role (Cashier) MUST be DENIED
        $this->assertFalse(
            $callback($this->unauthorizedCashier, $this->tenant->id, $this->store->id),
            'Cashier without KDS permissions must be denied'
        );

        // 4. Authorized Cook accessing an unassigned store MUST be DENIED
        $this->assertFalse(
            $callback($this->authorizedCook, $this->tenant->id, $this->otherStore->id),
            'Cook must not access channels for unassigned stores'
        );

        // 5. Staff with no stores assigned trying to access an invalid or multi-store channel MUST be DENIED (Fail-closed)
        $unassignedCook = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unassigned Cook',
            'email' => 'unassigned.'.uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'role_id' => $this->authorizedCook->role_id,
            'is_active' => true,
        ]);
        $this->assertFalse(
            $callback($unassignedCook, $this->tenant->id, 999999),
            'User with no store links must fail closed when accessing invalid store ID'
        );
        $this->assertFalse(
            $callback($unassignedCook, $this->tenant->id, $this->store->id),
            'User with no store links must fail closed when tenant has multiple stores'
        );
    }

    public function test_kds_order_created_broadcasts_on_exact_tenant_and_store_private_channel(): void
    {
        $order = Order::find($this->orderItem->order_id);
        $this->assertNotNull($order);

        $event = new KdsOrderCreated($order);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-kds.tenant.{$this->tenant->id}.store.{$this->store->id}", $channels[0]->name);
        $this->assertSame('kds.order.created', $event->broadcastAs());
    }

    public function test_kds_order_created_payload_contains_only_scalar_values_without_eloquent_instances(): void
    {
        $order = Order::find($this->orderItem->order_id);
        $this->assertNotNull($order);

        $event = new KdsOrderCreated($order);
        $payload = $event->broadcastWith();

        $this->assertIsArray($payload);
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame($order->order_code, $payload['order_number']);
        $this->assertSame($this->tenant->id, $payload['tenant_id']);
        $this->assertSame($this->store->id, $payload['store_id']);
        $this->assertSame('dine_in', $payload['order_type']);
        $this->assertIsString($payload['created_at']);

        // Assert strictly zero Eloquent Model instances in broadcast payload
        foreach ($payload as $key => $value) {
            $this->assertFalse(
                $value instanceof Model,
                "Broadcast payload key '{$key}' must not be an Eloquent model instance."
            );
        }
    }

    public function test_checkout_order_handler_dispatches_kds_order_created_event(): void
    {
        Event::fake([KdsOrderCreated::class]);

        $order = Order::find($this->orderItem->order_id);
        $this->assertNotNull($order);

        KdsOrderCreated::dispatch($order);

        Event::assertDispatched(KdsOrderCreated::class, function (KdsOrderCreated $event) use ($order) {
            return $event->orderId === $order->id
                && $event->tenantId === $this->tenant->id
                && $event->storeId === $this->store->id;
        });
    }
}
