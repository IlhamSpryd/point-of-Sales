<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Integration\Services\OutboundWebhookDispatcherService;
use App\Modules\Integration\Services\PaymentWebhookVerificationService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Wave6IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected OutboundWebhookDispatcherService $outboundService;

    protected PaymentWebhookVerificationService $paymentVerificationService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        Config::set('services.midtrans.server_key', 'test_wave6_midtrans_key');

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
            'permissions' => ['*'],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $this->outboundService = app(OutboundWebhookDispatcherService::class);
        $this->paymentVerificationService = app(PaymentWebhookVerificationService::class);
    }

    /**
     * T-INT-01: Webhook Midtrans dengan tanda tangan palsu otomatis ditolak dengan HTTP 401 dan tidak mengubah status pesanan kasir.
     */
    public function test_t_int_01_midtrans_webhook_with_forged_signature_returns_http_401_and_preserves_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_code' => 'ORD-WAVE6-INT-01',
            'idempotency_key' => Str::uuid()->toString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 150000.00,
            'subtotal_amount' => 150000.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'payment_method' => 'qris',
            'user_id' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);

        $forgedSignature = '0000000000forgedsignaturehash1111111111';

        $response = $this->postJson('/api/payment/notification', [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'signature_key' => $forgedSignature,
            'transaction_status' => 'settlement',
        ]);

        // Verifikasi HTTP 401 Unauthorized
        $response->assertStatus(401);

        // Verifikasi status pesanan di database TIDAK berubah (tetap Pending)
        $order->refresh();
        $this->assertEquals(OrderStatus::Pending, $order->order_status);
    }

    /**
     * T-INT-02: Webhook keluar terbukti memuat header X-Apeiron-Signature yang valid dan dapat diverifikasi via hash_equals(hash_hmac('sha256', ...)) di sisi penerima.
     */
    public function test_t_int_02_outbound_webhook_contains_valid_x_apeiron_signature_verified_via_hash_equals(): void
    {
        $secret = 'webhook_secret_hmac_test_key_123';
        $event = 'order.paid';
        $data = [
            'order_id' => 'ORD-WAVE6-001',
            'amount' => '250000.00',
            'customer' => 'PT Sukses Makmur',
        ];

        // 1. Dispatcher menyiapkan request webhook keluar
        $prepared = $this->outboundService->prepareWebhook(
            event: $event,
            data: $data,
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            secret: $secret
        );

        $headers = $prepared['headers'];
        $payload = $prepared['payload'];
        $timestamp = $prepared['timestamp'];

        // 2. Verifikasi keberadaan seluruh header kontrak kanonikal Apeiron
        $this->assertArrayHasKey('X-Apeiron-Signature', $headers);
        $this->assertArrayHasKey('X-Apeiron-Event', $headers);
        $this->assertArrayHasKey('X-Apeiron-Delivery-ID', $headers);
        $this->assertArrayHasKey('X-Apeiron-Timestamp', $headers);

        $this->assertEquals($event, $headers['X-Apeiron-Event']);
        $this->assertEquals((string) $timestamp, $headers['X-Apeiron-Timestamp']);

        // 3. Verifikasi tanda tangan di sisi penerima via verifySignature (memanfaatkan hash_equals)
        $signature = $headers['X-Apeiron-Signature'];
        $isValid = $this->outboundService->verifySignature($payload, $signature, $timestamp, $secret);
        $this->assertTrue($isValid, 'Tanda tangan HMAC-SHA256 valid wajib diverifikasi berhasil.');

        // 4. Verifikasi Replay Attack Protection: timestamp kedaluwarsa (> 300 detik) ditolak
        $expiredTimestamp = $timestamp - 301;
        $expiredSignature = $this->outboundService->computeSignature($payload, (string) $expiredTimestamp, $secret);
        $isExpiredValid = $this->outboundService->verifySignature($payload, $expiredSignature, $expiredTimestamp, $secret);
        $this->assertFalse($isExpiredValid, 'Replay attack dengan timestamp > 300 detik wajib ditolak.');
    }

    /**
     * T-INT-03: Panggilan API Sanctum dengan token yang tidak memiliki scope yang dipersyaratkan ditolak dengan HTTP 403 Forbidden.
     */
    public function test_t_int_03_sanctum_api_call_without_required_scope_is_rejected_with_http_403(): void
    {
        // 1. Token Sanctum dengan scope yang SALAH (hanya 'inventory:read', bukan 'orders:read')
        Sanctum::actingAs($this->user, ['inventory:read']);

        // Panggil endpoint /api/v1/orders yang mewajibkan scope 'orders:read'
        $responseWrongScope = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/orders');

        // Wajib ditolak dengan HTTP 403 Forbidden
        $responseWrongScope->assertStatus(403);
        $responseWrongScope->assertJson([
            'message' => 'Forbidden: Token lacks required scope [orders:read].',
        ]);

        // 2. Token Sanctum dengan scope yang BENAR ('orders:read')
        Sanctum::actingAs($this->user, ['orders:read']);

        $responseValid = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/orders');

        // Wajib berhasil dengan HTTP 200 OK
        $responseValid->assertStatus(200);
        $responseValid->assertJson([
            'status' => 'OK',
            'orders' => [],
        ]);

        // 3. Verifikasi isolasi tenant: token milik Tenant A mencoba mengakses dengan X-Tenant-ID milik Tenant B (999999) ditolak HTTP 403
        $responseCrossTenant = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => '999999',
        ])->getJson('/api/v1/orders');

        $responseCrossTenant->assertStatus(403);
        $responseCrossTenant->assertJson([
            'message' => 'Cross-tenant token usage detected.',
        ]);
    }
}
