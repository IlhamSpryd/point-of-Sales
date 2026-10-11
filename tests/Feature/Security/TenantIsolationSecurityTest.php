<?php

namespace Tests\Feature\Security;

use App\Enums\ExportTaskStatus;
use App\Models\ChannelOrderLog;
use App\Models\ExportTask;
use App\Models\Role;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\DatabaseErrorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class TenantIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTwoTenants();
    }

    /**
     * SEC-01: User model resolveRouteBinding harus fail-closed jika tenant context kosong.
     */
    public function test_user_resolve_route_binding_fails_closed_without_tenant_context(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $userModel = new User();

        // Tanpa tenant context dan tanpa bypass, resolusi user WAJIB return null (404)
        $resolved = $userModel->resolveRouteBinding($this->userAOwner->id);
        $this->assertNull($resolved, 'User route binding must return null (fail-closed) when tenant context is unset.');

        // Dengan tenant context A, User A berhasil di-resolve
        $context->setTenantId($this->tenantA->id);
        $resolvedA = $userModel->resolveRouteBinding($this->userAOwner->id);
        $this->assertNotNull($resolvedA);
        $this->assertEquals($this->userAOwner->id, $resolvedA->id);

        // Dengan tenant context A, User B (milik Tenant B) ditolak (return null / 404)
        $resolvedB = $userModel->resolveRouteBinding($this->userBBarista->id);
        $this->assertNull($resolvedB, 'User belonging to another tenant must not be resolved.');

        // Dalam mode explicit bypass, resolusi diizinkan
        $context->clear();
        $resolvedBypassed = $context->runWithoutTenant(function () use ($userModel) {
            return $userModel->resolveRouteBinding($this->userBBarista->id);
        });
        $this->assertNotNull($resolvedBypassed);
        $this->assertEquals($this->userBBarista->id, $resolvedBypassed->id);
    }

    /**
     * SEC-01: ExportTask download dan status menolak akses lintas tenant dengan 403 Forbidden.
     */
    public function test_cross_tenant_export_task_access_is_forbidden(): void
    {
        Storage::fake('local');
        $fakePath = 'exports/tenant_b_report.csv';
        Storage::disk('local')->put($fakePath, "id,amount\n1,5000\n");

        // Buat export task milik Tenant B
        $exportTaskB = ExportTask::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'store_id' => $this->storeB->id,
            'type' => 'orders',
            'status' => ExportTaskStatus::Completed,
            'file_path' => $fakePath,
            'requested_by' => $this->userBBarista->id,
            'completed_at' => now(),
        ]);

        // Login sebagai Owner Tenant A
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Mencoba akses status task milik Tenant B harus 403 atau 404
        $responseStatus = $this->getJson(route('exports.status', $exportTaskB));
        $this->assertTrue(in_array($responseStatus->status(), [403, 404], true), 'Owner Tenant A must not access Tenant B export status.');

        // Mencoba download file task milik Tenant B harus 403 atau 404
        $responseDownload = $this->get(route('exports.download', $exportTaskB));
        $this->assertTrue(in_array($responseDownload->status(), [403, 404], true), 'Owner Tenant A must not download Tenant B export file.');
    }

    /**
     * SEC-01 & SEC-04: Form validation menolak penetapan role milik tenant lain ke user.
     */
    public function test_assigning_cross_tenant_role_fails_validation(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $roleTenantB = app(TenantContext::class)->runAs($this->tenantB->id, null, function () {
            return Role::create([
                'name' => 'Barista B Role',
                'is_active' => true,
            ]);
        });
        $this->assertNotNull($roleTenantB);

        $response = $this->post(route('users.store'), [
            'name' => 'Staff Infiltrator',
            'email' => 'infiltrator@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_id' => $roleTenantB->id, // Role milik Tenant B!
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['role_id']);
        $this->assertDatabaseMissing('users', ['email' => 'infiltrator@test.com']);
    }

    /**
     * SEC-02: Webhook signature verification menolak header timestamp malformed / non-numeric tanpa TypeError.
     */
    public function test_webhook_signature_middleware_rejects_malformed_timestamp(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', ['id' => 'EXT-1'], [
            'X-Timestamp' => 'not-a-number',
            'X-Signature' => 'somesignature',
        ]);

        $response->assertStatus(401);
        $this->assertEquals('Timestamp invalid or expired', $response->json('message'));
    }

    /**
     * SEC-02: Webhook signature verification menolak timestamp kedaluwarsa.
     */
    public function test_webhook_signature_middleware_rejects_expired_timestamp(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');

        $expiredTimestamp = (string) (time() - 400); // 400 detik lalu (> 300s window)
        $payload = json_encode(['id' => 'EXT-1']);
        $signature = hash_hmac('sha256', $payload.$expiredTimestamp, 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', ['id' => 'EXT-1'], [
            'X-Timestamp' => $expiredTimestamp,
            'X-Signature' => $signature,
        ]);

        $response->assertStatus(401);
        $this->assertEquals('Timestamp invalid or expired', $response->json('message'));
    }

    /**
     * SEC-02: Webhook signature verification menerima request dengan signature HMAC yang valid.
     */
    public function test_webhook_signature_middleware_accepts_valid_signature(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');
        Config::set('services.webhooks.default_tenant_id', $this->tenantA->id);
        Config::set('services.webhooks.default_store_id', $this->storeA->id);

        $nowTimestamp = (string) time();
        $payloadArray = ['id' => 'EXT-VALID-1'];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload.$nowTimestamp, 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', $payloadArray, [
            'X-Timestamp' => $nowTimestamp,
            'X-Signature' => $signature,
        ]);

        $response->assertStatus(202);
        $this->assertEquals('Accepted for processing', $response->json('message'));
    }

    /**
     * SEC-02 & SEC-06: Webhook controller membedakan duplicate entry (200 OK) dari error database lainnya (500).
     */
    public function test_webhook_duplicate_order_returns_200_ok(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');
        Config::set('services.webhooks.default_tenant_id', $this->tenantA->id);
        Config::set('services.webhooks.default_store_id', $this->storeA->id);

        // Buat log pertama
        ChannelOrderLog::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'grabfood',
            'external_order_id' => 'EXT-DUP-1',
            'payload' => ['id' => 'EXT-DUP-1'],
            'status' => 'pending',
        ]);

        $nowTimestamp = (string) time();
        $payloadArray = ['id' => 'EXT-DUP-1'];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload.$nowTimestamp, 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', $payloadArray, [
            'X-Timestamp' => $nowTimestamp,
            'X-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Order already processed (duplicate)', $response->json('message'));
    }

    /**
     * SEC-02 & SEC-06: Error database non-duplicate (seperti FK constraint) TIDAK disamarkan sebagai 200 duplicate.
     */
    public function test_webhook_non_duplicate_database_error_returns_500(): void
    {
        Config::set('services.webhooks.grabfood_secret', 'secret123');
        // Tenant ID tidak valid (melanggar foreign key constraint)
        Config::set('services.webhooks.default_tenant_id', 999999);
        Config::set('services.webhooks.default_store_id', null);

        $nowTimestamp = (string) time();
        $payloadArray = ['id' => 'EXT-FK-FAIL-1'];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload.$nowTimestamp, 'secret123');

        $response = $this->postJson('/api/webhooks/grabfood/orders', $payloadArray, [
            'X-Timestamp' => $nowTimestamp,
            'X-Signature' => $signature,
        ]);

        $response->assertStatus(500);
        $this->assertEquals('Internal Server Error', $response->json('message'));
    }

    /**
     * SEC-07: Log webhook Midtrans tidak membocorkan signature_key atau PII customer mentah.
     */
    public function test_midtrans_logs_are_sanitized_without_exposing_signature(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        Config::set('services.midtrans.server_key', 'test-server-key');

        $payload = [
            'order_id' => $this->orderA->order_code,
            'status_code' => '200',
            'gross_amount' => '5000',
            'signature_key' => 'super-secret-signature-key',
            'transaction_status' => 'settlement',
            'customer_email' => 'victim@example.com',
            'customer_phone' => '08123456789',
        ];

        $this->postJson(route('midtrans.notification'), $payload);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                if ($message !== 'Midtrans webhook received:') {
                    return true;
                }
                // Pastikan signature_key dan customer_email TIDAK ada di context
                return ! isset($context['signature_key'])
                    && ! isset($context['customer_email'])
                    && ! isset($context['customer_phone'])
                    && isset($context['order_id']);
            });
    }

    /**
     * SEC-04: Sesi user yang dinonaktifkan diakhiri seketika dengan 403 Forbidden.
     */
    public function test_deactivated_user_session_is_terminated_on_next_request(): void
    {
        $this->actingAs($this->userAOwner);

        // Menonaktifkan user
        $this->userAOwner->update(['is_active' => false]);

        // Request berikutnya ke route yang dilindungi tenant.context harus ditolak dengan 403
        $response = $this->get(route('home'));
        $response->assertStatus(403);
        $this->assertGuest();
    }
}
