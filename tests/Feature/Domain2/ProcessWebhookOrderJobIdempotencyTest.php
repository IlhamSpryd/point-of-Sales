<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Jobs\ProcessWebhookOrderJob;
use App\Models\Category;
use App\Models\ChannelOrderLog;
use App\Models\ChannelProductMapping;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\SystemUserResolver;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessWebhookOrderJobIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Product $product;
    protected User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Webhook Tenant',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Online Store',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $channelEmail = $this->tenant->id === 1
            ? config('pos.system_users.channel_order_email', 'channel@system.local')
            : "channel.t{$this->tenant->id}@system.local";

        $this->systemUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => $channelEmail,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Food',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Burger',
            'product_price' => 50000,
            'stock' => 100,
            'is_active' => true,
        ]);

        ChannelProductMapping::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_product_id' => 'GF-BURGER-1',
            'product_id' => $this->product->id,
        ]);
    }

    public function test_concurrent_duplicate_webhook_resolves_to_completed_with_order_id(): void
    {
        $payload = [
            'order_id' => 'GF-ORD-999',
            'items' => [
                [
                    'item_id' => 'GF-BURGER-1',
                    'quantity' => 2,
                    'price' => 50000,
                ],
            ],
        ];

        $log = ChannelOrderLog::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-ORD-999',
            'status' => 'pending',
            'payload' => $payload,
        ]);

        $service = app(TransactionService::class);

        // Run worker 1 -> creates order successfully
        $job1 = new ProcessWebhookOrderJob($log, $this->tenant->id, $this->store->id);
        $job1->handle($service);

        $log->refresh();
        $this->assertEquals('completed', $log->status);
        $this->assertNotNull($log->order_id);
        $orderId = $log->order_id;

        // Reset log to pending to simulate worker 2 racing
        $log->update(['status' => 'pending']);

        // Run worker 2 -> should catch idempotency, not fail, and attach existing order_id
        $job2 = new ProcessWebhookOrderJob($log, $this->tenant->id, $this->store->id);
        $job2->handle($service);

        $log->refresh();
        $this->assertEquals('completed', $log->status, 'Second worker should mark completed, not failed');
        $this->assertEquals($orderId, $log->order_id);
        $this->assertNull($log->error_message);
    }
}
