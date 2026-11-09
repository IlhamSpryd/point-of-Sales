<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurant;

use App\Enums\KitchenStation;
use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Events\KdsItemUpdated;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Services\KdsService;
use App\Services\Context\TenantContext;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class KdsRealtimeBroadcastingTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $cookUser;

    private User $managerUser;

    private Order $order;

    private Product $product;

    private OrderItem $orderItem;

    private KdsService $kdsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->kdsService = app(KdsService::class);

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenant = Tenant::create([
                'name' => 'KDS Broadcast Bistro',
                'slug' => 'kds-bistro-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->store = Store::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Main Dining Hall',
                'code' => 'KDS-'.uniqid(),
            ]);

            $cookRole = Role::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Cook',
                'permissions' => ['kds.view', 'kds.update', 'kds.access'],
                'is_admin' => false,
            ]);
            $managerRole = Role::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Manager',
                'permissions' => ['orders.*'],
                'is_admin' => false,
            ]);

            $this->cookUser = User::create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $cookRole->id,
                'name' => 'Chef Marco',
                'email' => 'marco.'.uniqid().'@kds.test',
                'password' => bcrypt('secret123'),
                'is_active' => true,
            ]);

            $this->managerUser = User::create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $managerRole->id,
                'name' => 'Manager Diana',
                'email' => 'diana.'.uniqid().'@kds.test',
                'password' => bcrypt('secret123'),
                'is_active' => true,
            ]);

            $category = Category::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Hot Main Courses',
                'kitchen_station' => KitchenStation::Kitchen,
            ]);

            $this->product = Product::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $category->id,
                'product_name' => 'Truffle Wagyu Burger',
                'product_code' => 'FNB-BGR-01',
                'product_price' => 125000.00,
                'product_type' => 'standard',
                'kitchen_station' => KitchenStation::Kitchen,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $this->order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_code' => 'ORD-KDS-'.uniqid(),
            'order_date' => now(),
            'user_id' => $this->cookUser->id,
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 125000.00,
            'subtotal_amount' => 125000.00,
            'order_type' => 'dine_in',
            'idempotency_key' => 'kds-order-'.uniqid(),
        ]);

        $this->orderItem = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 125000.00,
            'order_subtotal' => 125000.00,
            'preparation_status' => PreparationStatus::Pending->value,
        ]);
    }

    public function test_claim_dispatches_kds_item_updated_broadcast_event(): void
    {
        Event::fake([KdsItemUpdated::class]);

        $item = $this->kdsService->claim($this->orderItem->id, $this->cookUser->id);

        $this->assertSame(PreparationStatus::Brewing, $item->preparation_status);
        $this->assertSame($this->cookUser->id, $item->processed_by);

        Event::assertDispatched(KdsItemUpdated::class, function (KdsItemUpdated $event) {
            return $event->orderItemId === $this->orderItem->id
                && $event->orderId === $this->order->id
                && $event->action === 'claimed'
                && $event->preparationStatus === 'brewing'
                && $event->processedBy === $this->cookUser->id
                && $event->station === 'kitchen';
        });
    }

    public function test_mark_ready_dispatches_kds_item_updated_broadcast_event(): void
    {
        // First claim
        $this->kdsService->claim($this->orderItem->id, $this->cookUser->id);

        Event::fake([KdsItemUpdated::class]);

        $item = $this->kdsService->markReady($this->orderItem->id, $this->cookUser->id);

        $this->assertSame(PreparationStatus::Ready, $item->preparation_status);

        Event::assertDispatched(KdsItemUpdated::class, function (KdsItemUpdated $event) {
            return $event->orderItemId === $this->orderItem->id
                && $event->action === 'ready'
                && $event->preparationStatus === 'ready'
                && $event->processedBy === $this->cookUser->id;
        });
    }

    public function test_release_and_recall_dispatch_broadcast_events(): void
    {
        // 1. Claim then Release
        $this->kdsService->claim($this->orderItem->id, $this->cookUser->id);

        Event::fake([KdsItemUpdated::class]);

        $this->kdsService->release($this->orderItem->id, $this->managerUser->id, canManage: true);

        Event::assertDispatched(KdsItemUpdated::class, function (KdsItemUpdated $event) {
            return $event->orderItemId === $this->orderItem->id
                && $event->action === 'released'
                && $event->preparationStatus === 'pending'
                && $event->processedBy === null;
        });

        // 2. Re-claim, Ready, then Recall
        $this->kdsService->claim($this->orderItem->id, $this->cookUser->id);
        $this->kdsService->markReady($this->orderItem->id, $this->cookUser->id);

        Event::fake([KdsItemUpdated::class]);

        $this->kdsService->recall($this->orderItem->id, $this->cookUser->id, canManage: false);

        Event::assertDispatched(KdsItemUpdated::class, function (KdsItemUpdated $event) {
            return $event->orderItemId === $this->orderItem->id
                && $event->action === 'recalled'
                && $event->preparationStatus === 'brewing';
        });
    }

    public function test_mark_served_dispatches_broadcast_event(): void
    {
        $this->kdsService->claim($this->orderItem->id, $this->cookUser->id);

        Event::fake([KdsItemUpdated::class]);

        $this->kdsService->markServed($this->orderItem->id, $this->cookUser->id);

        Event::assertDispatched(KdsItemUpdated::class, function (KdsItemUpdated $event) {
            return $event->orderItemId === $this->orderItem->id
                && $event->action === 'served'
                && $event->preparationStatus === 'ready';
        });
    }

    public function test_broadcast_on_scopes_to_tenant_and_store_channel(): void
    {
        $event = new KdsItemUpdated($this->orderItem, 'status_check');

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-tenant.{$this->tenant->id}.store.{$this->store->id}.kds", $channels[0]->name);
        $this->assertSame('kds.item.updated', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($this->orderItem->id, $payload['item_id']);
        $this->assertSame($this->order->id, $payload['order_id']);
        $this->assertSame($this->tenant->id, $payload['tenant_id']);
        $this->assertSame($this->store->id, $payload['store_id']);
        $this->assertNotEmpty($payload['updated_at']);
    }

    public function test_concurrent_claim_prevention_under_pessimistic_lock(): void
    {
        Event::fake([KdsItemUpdated::class]);

        // Cook A claims the item successfully
        $claimed = $this->kdsService->claim($this->orderItem->id, $this->cookUser->id);
        $this->assertSame(PreparationStatus::Brewing, $claimed->preparation_status);
        $this->assertSame($this->cookUser->id, $claimed->processed_by);

        // Another staff member attempts to claim the exact same item concurrently
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->kdsService->claim($this->orderItem->id, $this->managerUser->id);

        // Verify only 1 broadcast was dispatched
        Event::assertDispatchedTimes(KdsItemUpdated::class, 1);
    }

    public function test_channel_authorization_allows_tenant_staff_and_blocks_cross_tenant_intruders(): void
    {
        $channels = \Illuminate\Support\Facades\Broadcast::getChannels();
        $this->assertArrayHasKey('tenant.{tenantId}.store.{storeId}.kds', $channels);
        $this->assertArrayHasKey('tenant.{tenantId}.kds', $channels);

        $storeCallback = $channels['tenant.{tenantId}.store.{storeId}.kds'];
        $tenantCallback = $channels['tenant.{tenantId}.kds'];

        // 1. Cook with KDS permissions can access their store channel and tenant channel
        $this->assertTrue($storeCallback($this->cookUser, $this->tenant->id, $this->store->id));
        $this->assertTrue($tenantCallback($this->cookUser, $this->tenant->id));

        // 2. Cross-tenant intruder fails closed
        $intruder = app(TenantContext::class)->runWithoutTenant(function () {
            $intruderTenant = Tenant::create([
                'name' => 'Intruder Tenant',
                'slug' => 'intruder-'.uniqid(),
                'status' => 'active',
            ]);

            return User::withoutGlobalScopes()->create([
                'tenant_id' => $intruderTenant->id,
                'name' => 'Malicious Sniffer',
                'email' => 'intruder.'.uniqid().'@test.com',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
        });

        $this->assertFalse($storeCallback($intruder, $this->tenant->id, $this->store->id));
        $this->assertFalse($tenantCallback($intruder, $this->tenant->id));

        // 3. User without KDS permissions fails closed
        $unauthorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unauthorized Cashier',
            'email' => 'cashier.'.uniqid().'@kds.test',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        $this->assertFalse($storeCallback($unauthorizedUser, $this->tenant->id, $this->store->id));
        $this->assertFalse($tenantCallback($unauthorizedUser, $this->tenant->id));
    }
}
