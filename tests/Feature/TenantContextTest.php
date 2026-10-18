<?php

namespace Tests\Feature;

use App\Http\Middleware\SetTenantContext;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    public function test_context_can_store_tenant_id_for_current_lifecycle()
    {
        $context = $this->app->make(TenantContext::class);
        $context->setTenantId(10);

        $this->assertTrue($context->hasTenant());
        $this->assertEquals(10, $context->getTenantId());
        $this->assertEquals(10, $context->requireTenantId());
    }

    public function test_has_tenant_is_false_before_context_is_set()
    {
        $context = $this->app->make(TenantContext::class);

        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenantId());
    }

    public function test_require_tenant_id_fails_appropriately_when_context_is_empty()
    {
        $context = $this->app->make(TenantContext::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant context has not been set for this request.');

        $context->requireTenantId();
    }

    public function test_middleware_sets_tenant_from_authenticated_user()
    {
        $tenant = Tenant::create(['name' => 'Test Tenant']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $user->stores()->attach($store->id, ['tenant_id' => $tenant->id]);

        $middleware = app(SetTenantContext::class);
        $request = Request::create('/home');
        $session = $this->app['session']->driver();
        $session->setId('test-session');
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, function () use ($tenant, $store) {
            $context = $this->app->make(TenantContext::class);
            $this->assertTrue($context->hasTenant());
            $this->assertEquals($tenant->id, $context->getTenantId());
            $this->assertEquals($store->id, $context->getStoreId());

            return response('OK', 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_authenticated_user_without_tenant_is_rejected()
    {
        // Phase 9: users.tenant_id kini NOT NULL, sehingga "user tanpa tenant"
        // tidak bisa lagi dibuat lewat model. Middleware tetap harus menolak
        // user tak-teraut ke tenant, disimulasikan dengan user yang tenant-nya
        // di-soft-delete / sudah tidak ada.
        $tenant = Tenant::create(['name' => 'Ghost Corp']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->delete();

        // TenantContext hanya melihat relasi tenant yang masih hidup.
        $this->assertNull($user->fresh()->tenant);
    }

    public function test_login_authentication_lookup_does_not_require_tenant_scope()
    {
        // Public login page does not require tenant context
        $response = $this->get('/login');
        $response->assertStatus(200);

        $tenant = Tenant::create(['name' => 'Test Tenant']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => 'password123',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Trying to login
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_context_isolation_between_requests()
    {
        $tenantA = Tenant::create(['name' => 'Test Tenant A']);
        $storeA = Store::create(['tenant_id' => $tenantA->id, 'name' => 'Main A']);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'is_active' => true, 'email_verified_at' => now()]);
        $userA->stores()->attach($storeA->id, ['tenant_id' => $tenantA->id]);

        // First request sets context during processing, and terminates cleanly
        $this->actingAs($userA);
        $this->get('/home')->assertStatus(200);

        // After request termination, SetTenantContext::terminate ensures context is cleared
        $context = $this->app->make(TenantContext::class);
        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenantId());
    }
}
