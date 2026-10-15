<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ChannelOrderLog;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class WebhookMultiTenantRoutingTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    public function test_webhook_routes_with_tenant_parameter_in_url(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');
        Config::set('services.webhooks.default_tenant_id', null);

        $nowTimestamp = (string) time();
        $payloadArray = ['id' => 'ORDER-TENANT-A-01', 'items' => []];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload.$nowTimestamp, 'secret123');

        $response = $this->postJson("/api/tenants/{$this->tenantA->id}/webhooks/grabfood/orders", $payloadArray, [
            'X-Timestamp' => $nowTimestamp,
            'X-Signature' => $signature,
        ]);

        $response->assertStatus(202);

        $log = ChannelOrderLog::withoutGlobalScopes()
            ->where('external_order_id', 'ORDER-TENANT-A-01')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->tenantA->id, $log->tenant_id);
    }

    public function test_webhook_routes_with_x_tenant_id_header(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');
        Config::set('services.webhooks.default_tenant_id', null);

        $nowTimestamp = (string) time();
        $payloadArray = ['id' => 'ORDER-TENANT-B-01', 'items' => []];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload.$nowTimestamp, 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', $payloadArray, [
            'X-Timestamp' => $nowTimestamp,
            'X-Signature' => $signature,
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ]);

        $response->assertStatus(202);

        $log = ChannelOrderLog::withoutGlobalScopes()
            ->where('external_order_id', 'ORDER-TENANT-B-01')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->tenantB->id, $log->tenant_id);
    }

    public function test_webhook_fails_closed_when_no_tenant_is_supplied(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');
        Config::set('services.webhooks.default_tenant_id', null);

        $nowTimestamp = (string) time();
        $payloadArray = ['id' => 'ORDER-NO-TENANT', 'items' => []];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload.$nowTimestamp, 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', $payloadArray, [
            'X-Timestamp' => $nowTimestamp,
            'X-Signature' => $signature,
        ]);

        $response->assertStatus(400);
    }
}
