<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ChannelOrderLog;
use App\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class WebhookSecurityAuditTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        Config::set('services.webhooks.grabfood_secret', 'audit_grabfood_secret_key_123');
        Config::set('services.webhooks.default_tenant_id', null);
        Config::set('services.webhooks.default_store_id', null);
    }

    private function generateSignature(string $rawBody, string $timestamp, string $secret = 'audit_grabfood_secret_key_123'): string
    {
        return hash_hmac('sha256', $rawBody.$timestamp, $secret);
    }

    public function test_rejects_webhook_with_invalid_signature(): void
    {
        $payload = ['id' => 'EXT-ORDER-SIG-FAIL', 'items' => []];
        $timestamp = (string) time();

        $response = $this->postJson(
            "/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => 'invalid_tampered_signature_hex_value',
            ]
        );

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid signature']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-SIG-FAIL',
        ]);
    }

    public function test_rejects_webhook_with_expired_timestamp_replay(): void
    {
        $payload = ['id' => 'EXT-ORDER-EXPIRED', 'items' => []];
        $rawBody = json_encode($payload);
        $expiredTimestamp = (string) (time() - 360); // 6 menit yang lalu (> 300s window)
        $signature = $this->generateSignature($rawBody, $expiredTimestamp);

        $response = $this->postJson(
            "/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $expiredTimestamp,
                'X-Signature' => $signature,
            ]
        );

        $response->assertStatus(401)
            ->assertJson(['message' => 'Timestamp invalid or expired']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-EXPIRED',
        ]);
    }

    public function test_rejects_webhook_with_unknown_tenant(): void
    {
        $payload = ['id' => 'EXT-ORDER-UNKNOWN-TENANT', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = $this->generateSignature($rawBody, $timestamp);

        $response = $this->postJson(
            '/api/tenants/999999/webhooks/grabfood/orders',
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ]
        );

        $response->assertStatus(404)
            ->assertJson(['message' => 'Tenant not found']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-UNKNOWN-TENANT',
        ]);
    }

    public function test_rejects_webhook_with_tenant_mismatch_between_route_and_header(): void
    {
        $payload = ['id' => 'EXT-ORDER-MISMATCH-1', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = $this->generateSignature($rawBody, $timestamp);

        // Route mengarah ke Tenant A, tetapi Header meminta Tenant B
        $response = $this->postJson(
            "/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Tenant-ID' => (string) $this->tenantB->id,
            ]
        );

        $response->assertStatus(400)
            ->assertJson(['message' => 'Tenant ID mismatch detected']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-MISMATCH-1',
        ]);
    }

    public function test_rejects_webhook_with_tenant_mismatch_between_header_and_query(): void
    {
        $payload = ['id' => 'EXT-ORDER-MISMATCH-2', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = $this->generateSignature($rawBody, $timestamp);

        // Header meminta Tenant A, Query meminta Tenant B
        $response = $this->postJson(
            "/api/webhooks/grabfood/orders?tenant_id={$this->tenantB->id}",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Tenant-ID' => (string) $this->tenantA->id,
            ]
        );

        $response->assertStatus(400)
            ->assertJson(['message' => 'Tenant ID mismatch detected']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-MISMATCH-2',
        ]);
    }

    public function test_fails_closed_when_no_tenant_supplied_even_with_default_tenant_configured(): void
    {
        Config::set('services.webhooks.default_tenant_id', $this->tenantA->id);

        $payload = ['id' => 'EXT-ORDER-NO-TENANT', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = $this->generateSignature($rawBody, $timestamp);

        // Tidak ada tenant di route, header, maupun query
        $response = $this->postJson(
            '/api/webhooks/grabfood/orders',
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ]
        );

        $response->assertStatus(400)
            ->assertJson(['message' => 'Tenant context missing']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-NO-TENANT',
        ]);
    }

    public function test_rejects_store_id_not_belonging_to_tenant(): void
    {
        $payload = ['id' => 'EXT-ORDER-STORE-MISMATCH', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = $this->generateSignature($rawBody, $timestamp);

        // Tenant A mencoba memproses order dengan Store milik Tenant B
        $response = $this->postJson(
            "/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Store-ID' => (string) $this->storeB->id,
            ]
        );

        $response->assertStatus(422)
            ->assertJson(['message' => 'Store does not belong to tenant']);

        $this->assertDatabaseMissing('channel_order_logs', [
            'external_order_id' => 'EXT-ORDER-STORE-MISMATCH',
        ]);
    }

    public function test_successful_webhook_processing_and_idempotent_retry_handling(): void
    {
        \Illuminate\Support\Facades\Queue::fake([
            \App\Jobs\ProcessWebhookOrderJob::class,
        ]);

        $payload = ['id' => 'EXT-ORDER-IDEMP-001', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = $this->generateSignature($rawBody, $timestamp);

        // Permintaan pertama: Diterima untuk diproses (HTTP 202 Accepted)
        $response1 = $this->postJson(
            "/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Store-ID' => (string) $this->storeA->id,
            ]
        );

        $response1->assertStatus(202)
            ->assertJson(['message' => 'Accepted for processing']);

        $this->assertDatabaseHas('channel_order_logs', [
            'tenant_id' => $this->tenantA->id,
            'provider' => 'grabfood',
            'external_order_id' => 'EXT-ORDER-IDEMP-001',
            'status' => 'pending',
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessWebhookOrderJob::class, 1);

        // Permintaan kedua (Retry event yang sama dari provider):
        // Harus ditangani secara idempoten tanpa duplikasi (HTTP 200 OK)
        $response2 = $this->postJson(
            "/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Store-ID' => (string) $this->storeA->id,
            ]
        );

        $response2->assertStatus(200)
            ->assertJson(['message' => 'Order already processed (duplicate)']);

        // Pastikan job TIDAK di-dispatch ulang pada retry request
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessWebhookOrderJob::class, 1);

        // Pastikan hanya terdapat TEPAT SATU rekaman di database
        $count = ChannelOrderLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('provider', 'grabfood')
            ->where('external_order_id', 'EXT-ORDER-IDEMP-001')
            ->count();

        $this->assertSame(1, $count);
    }
}
