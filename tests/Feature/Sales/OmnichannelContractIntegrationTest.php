<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\ProcessWebhookOrderJob;
use App\Models\Category;
use App\Models\ChannelOrderLog;
use App\Models\ChannelProductMapping;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Services\Omnichannel\ChannelOrderNormalizerFactory;
use App\Modules\Sales\Services\Omnichannel\GenericChannelOrderNormalizer;
use App\Modules\Sales\Services\Omnichannel\OmnichannelReconciliationService;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OmnichannelContractIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected Product $productPizza;

    protected Product $productSoda;

    protected User $systemUserA;

    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $systemUserB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        Config::set('services.webhooks.gofood_secret', 'test_gofood_secret_123');

        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Omni Resto Jakarta',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Resto Jakarta Pusat',
        ]);
        $context->setStoreId($this->storeA->id);

        $channelEmailA = $this->tenantA->id === 1
            ? config('pos.system_users.channel_order_email', 'channel@system.local')
            : "channel.t{$this->tenantA->id}@system.local";

        $this->systemUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'email' => $channelEmailA,
        ]);

        $categoryA = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Makanan Utama',
        ]);

        $this->productPizza = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $categoryA->id,
            'product_name' => 'Pizza Margherita',
            'product_price' => 85000,
            'stock' => 50,
        ]);

        $this->productSoda = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $categoryA->id,
            'product_name' => 'Soda Can',
            'product_price' => 15000,
            'stock' => 100,
        ]);

        ChannelProductMapping::create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_product_id' => 'GF-PIZZA-01',
            'product_id' => $this->productPizza->id,
        ]);

        ChannelProductMapping::create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'generic',
            'external_product_id' => 'EXT-SODA-01',
            'product_id' => $this->productSoda->id,
        ]);

        // Setup Tenant B for isolation
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Kompetitor Omni Bandung',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantB->id);
        $this->storeB = Store::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Resto Bandung',
        ]);
        $context->setStoreId($this->storeB->id);

        $channelEmailB = "channel.t{$this->tenantB->id}@system.local";
        $this->systemUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'email' => $channelEmailB,
        ]);

        // Reset to Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
    }

    private function generateSignature(string $rawBody, string $timestamp, string $secret = 'test_gofood_secret_123'): string
    {
        return hash_hmac('sha256', $rawBody.$timestamp, $secret);
    }

    public function test_generic_channel_normalizer_contract(): void
    {
        $normalizer = ChannelOrderNormalizerFactory::make('generic');
        $this->assertInstanceOf(GenericChannelOrderNormalizer::class, $normalizer);

        $payload = [
            'order_id' => 'MKT-ORD-1001',
            'customer_name' => 'Dewi Sartika',
            'customer_phone' => '+628123450001',
            'customer_email' => 'dewi@omni.test',
            'notes' => 'Tolong pedas sedang',
            'items' => [
                [
                    'external_product_id' => 'EXT-SODA-01',
                    'name' => 'Soda Segar Dingin',
                    'quantity' => 3,
                    'unit_price' => '15000.00',
                ],
            ],
            'channel' => 'tiktokshop',
            'delivery_fee' => '10000.00',
        ];

        $normalized = $normalizer->normalize($payload);

        $this->assertEquals('MKT-ORD-1001', $normalized->externalOrderId);
        $this->assertEquals('Dewi Sartika', $normalized->customerName);
        $this->assertEquals('+628123450001', $normalized->customerPhone);
        $this->assertEquals('dewi@omni.test', $normalized->customerEmail);
        $this->assertEquals('tiktokshop', $normalized->channelCode);
        $this->assertCount(1, $normalized->items);
        $this->assertEquals('EXT-SODA-01', $normalized->items[0]->externalProductId);
        $this->assertEquals(3, $normalized->items[0]->quantity);
    }

    public function test_webhook_order_processes_with_customer_auto_matching(): void
    {
        $payload = [
            'order_id' => 'GF-OMNI-9001',
            'customer_detail' => [
                'name' => 'Andi Wijaya',
                'phone_number' => '+6287711223344',
            ],
            'items' => [
                [
                    'item_id' => 'GF-PIZZA-01',
                    'quantity' => 1,
                    'price' => 999999, // Harga manipulasi dari frontend eksternal
                ],
            ],
        ];

        $log = ChannelOrderLog::create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-OMNI-9001',
            'status' => 'pending',
            'payload' => $payload,
        ]);

        $service = app(TransactionService::class);
        $job = new ProcessWebhookOrderJob($log, $this->tenantA->id, $this->storeA->id);
        $job->handle($service);

        $log->refresh();
        $this->assertEquals('completed', $log->status);
        $this->assertNotNull($log->order_id);

        $order = Order::withoutGlobalScopes()->findOrFail($log->order_id);

        // 1. Verifikasi Zero-Trust pricing: subtotal Rp 85.000 dihitung dari internal Product, bukan 999.999
        $this->assertEquals(85000, (int) $order->subtotal_amount);
        $this->assertEquals(94400, (int) $order->order_amount);

        // 2. Verifikasi Customer Auto-Matching (Modul 6): Pelanggan otomatis terbuat dan dikaitkan
        $this->assertNotNull($order->customer_id);
        $customer = Customer::withoutGlobalScopes()->findOrFail($order->customer_id);
        $this->assertEquals('+6287711223344', $customer->phone);
        $this->assertEquals('Andi Wijaya', $customer->name);
        $this->assertStringStartsWith('CUST-OMNI-', $customer->customer_code);

        // 3. Webhook kedua dari pelanggan yang sama menggunakan customer_id yang sudah ada
        $payload2 = [
            'order_id' => 'GF-OMNI-9002',
            'customer_detail' => [
                'name' => 'Andi Wijaya',
                'phone_number' => '+6287711223344',
            ],
            'items' => [
                [
                    'item_id' => 'GF-PIZZA-01',
                    'quantity' => 2,
                ],
            ],
        ];

        $log2 = ChannelOrderLog::create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-OMNI-9002',
            'status' => 'pending',
            'payload' => $payload2,
        ]);

        $job2 = new ProcessWebhookOrderJob($log2, $this->tenantA->id, $this->storeA->id);
        $job2->handle($service);

        $log2->refresh();
        $order2 = Order::withoutGlobalScopes()->findOrFail($log2->order_id);
        $this->assertEquals($customer->id, $order2->customer_id);

        // Pastikan tidak ada duplikasi customer baru dengan no hp yang sama
        $customerCount = Customer::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('phone', '+6287711223344')
            ->count();
        $this->assertEquals(1, $customerCount);
    }

    public function test_webhook_api_duplicate_returns_200_without_duplication(): void
    {
        $payload = [
            'order_id' => 'GF-API-DUP-1',
            'customer_detail' => [
                'name' => 'Budi Santoso',
                'phone_number' => '+628111222333',
            ],
            'items' => [
                [
                    'item_id' => 'GF-PIZZA-01',
                    'quantity' => 1,
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $timestamp1 = (string) time();
        $sig1 = $this->generateSignature($rawBody, $timestamp1);

        // Webhook Request 1 -> 202 Accepted
        $response1 = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
            'X-Store-ID' => (string) $this->storeA->id,
            'X-Timestamp' => $timestamp1,
            'X-Signature' => $sig1,
        ])->postJson("/api/tenants/{$this->tenantA->id}/webhooks/gofood/orders", $payload);

        $response1->assertStatus(202)
            ->assertJson(['message' => 'Accepted for processing']);

        $this->assertDatabaseHas('channel_order_logs', [
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-API-DUP-1',
        ]);

        // Webhook Request 2 (Retry dengan ID sama) -> 200 Duplicate Handled
        $timestamp2 = (string) time();
        $sig2 = $this->generateSignature($rawBody, $timestamp2);

        $response2 = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
            'X-Store-ID' => (string) $this->storeA->id,
            'X-Timestamp' => $timestamp2,
            'X-Signature' => $sig2,
        ])->postJson("/api/tenants/{$this->tenantA->id}/webhooks/gofood/orders", $payload);

        $response2->assertStatus(200)
            ->assertJson(['message' => 'Order already processed (duplicate)']);

        // Pastikan hanya tepat 1 baris log dibuat
        $logCount = ChannelOrderLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('provider', 'gofood')
            ->where('external_order_id', 'GF-API-DUP-1')
            ->count();

        $this->assertEquals(1, $logCount);
    }

    public function test_omnichannel_reconciliation_service_handles_drift(): void
    {
        $reconciliationService = app(OmnichannelReconciliationService::class);

        // Skenario 1: Log tertinggal di 'processing' padahal Order sebenarnya sudah ada
        $order = Order::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_code' => 'ORD-OMNI-EXISTING',
            'order_date' => Carbon::today()->toDateString(),
            'order_amount' => 85000,
            'subtotal_amount' => 85000,
            'order_status' => 'Paid',
            'user_id' => $this->systemUserA->id,
            'order_type' => 'Takeaway',
            'idempotency_key' => substr(hash('sha256', 'channel:gofood:GF-STUCK-1'), 0, 36),
        ]);

        $stuckLogWithOrder = ChannelOrderLog::create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-STUCK-1',
            'status' => 'processing',
            'payload' => ['order_id' => 'GF-STUCK-1'],
        ]);
        DB::table('channel_order_logs')
            ->where('id', $stuckLogWithOrder->id)
            ->update([
                'created_at' => Carbon::now()->subMinutes(30),
                'updated_at' => Carbon::now()->subMinutes(30),
            ]);

        // Skenario 2: Log 'pending' yang terabaikan lama (> 15 menit) dan TIDAK ada order
        $stuckLogOrphan = ChannelOrderLog::create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-ORPHAN-2',
            'status' => 'pending',
            'payload' => ['order_id' => 'GF-ORPHAN-2'],
        ]);
        DB::table('channel_order_logs')
            ->where('id', $stuckLogOrphan->id)
            ->update([
                'created_at' => Carbon::now()->subMinutes(45),
                'updated_at' => Carbon::now()->subMinutes(45),
            ]);

        // Jalankan rekonsiliasi
        $result = $reconciliationService->reconcileChannelOrders($this->tenantA->id, 15);

        $this->assertEquals(2, $result['checked']);
        $this->assertEquals(1, $result['synced_completed']);
        $this->assertEquals(1, $result['marked_failed']);

        // Log 1 harus ter-update ke completed dan menunjuk ke Order ID yang sah
        $stuckLogWithOrder->refresh();
        $this->assertEquals('completed', $stuckLogWithOrder->status);
        $this->assertEquals($order->id, $stuckLogWithOrder->order_id);

        // Log 2 harus ditandai failed karena timeout
        $stuckLogOrphan->refresh();
        $this->assertEquals('failed', $stuckLogOrphan->status);
        $this->assertStringContainsString('Batas waktu pemrosesan webhook habis', $stuckLogOrphan->error_message);
    }
}
