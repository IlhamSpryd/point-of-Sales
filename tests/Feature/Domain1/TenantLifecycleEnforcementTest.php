<?php

namespace Tests\Feature\Domain1;

use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Domain 1 — Penegakan Siklus Hidup Tenant (Tenant Lifecycle Enforcement).
 *
 * Menguji bahwa tenant yang ditangguhkan (suspended) atau dihapus (soft-deleted):
 * 1. Tidak dapat mengakses rute web operasional (SetTenantContext -> 403 + logout).
 * 2. Tidak dapat login (LoginRequest -> error validasi).
 * 3. Tidak dapat diakses via QR self-order meja (ResolveTableFromToken -> 403).
 * 4. Tidak dapat melanjutkan sesi meja yang sedang berjalan (EnsureTableSession -> 403).
 * 5. Ditolak pada webhook order masuk (WebhookController -> 403).
 */
class TenantLifecycleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantFixture(string $accessStatus = Tenant::ACCESS_ACTIVE, string $status = 'active'): array
    {
        $tenant = Tenant::create([
            'name' => 'Kedai Kopi Uji',
            'slug' => 'kedai-uji-' . uniqid(),
            'status' => $status,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => $accessStatus,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Utama',
            'code' => 'CAB-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $role = new Role();
        $role->tenant_id = $tenant->id;
        $role->name = 'Owner';
        $role->permissions = ['*'];
        $role->is_active = true;
        $role->save();

        $user = new User();
        $user->tenant_id = $tenant->id;
        $user->role_id = $role->id;
        $user->name = 'Owner Test';
        $user->email = 'owner.test@example.com';
        $user->email_normalized = 'owner.test@example.com';
        $user->password_hash = bcrypt('Password123!');
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $user->stores()->attach($store->id, ['tenant_id' => $tenant->id]);

        return [$tenant, $store, $user];
    }

    public function test_suspended_tenant_is_blocked_on_web_routes_and_logged_out(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_SUSPENDED);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_tenant_with_status_suspended_is_blocked_on_web_routes(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_ACTIVE, 'suspended');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_soft_deleted_tenant_is_blocked_on_web_routes(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_ACTIVE);
        $tenant->delete();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_suspended_tenant_cannot_login(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_SUSPENDED);

        $response = $this->post('/login', [
            'email' => 'owner.test@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_suspended_tenant_is_blocked_on_qr_self_order_initial_scan(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_SUSPENDED);

        $token = (string) Str::uuid();
        $table = app(TenantContext::class)->runAs($tenant->id, $store->id, function () use ($token) {
            return Table::create([
                'table_name' => 'Meja 01',
                'table_code' => 'YVL-TBL-001',
                'capacity' => 4,
                'is_active' => true,
                'operational_status' => 'available',
                'secure_token' => $token,
            ]);
        });

        $response = $this->get(route('customer.menu.index', ['token' => $token]));

        $response->assertStatus(403);
    }

    public function test_suspended_tenant_is_blocked_mid_session_on_cart(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_ACTIVE);

        $token = (string) Str::uuid();
        $table = app(TenantContext::class)->runAs($tenant->id, $store->id, function () use ($token) {
            return Table::create([
                'table_name' => 'Meja 02',
                'table_code' => 'YVL-TBL-002',
                'capacity' => 4,
                'is_active' => true,
                'operational_status' => 'available',
                'secure_token' => $token,
            ]);
        });

        // Simulasikan sesi pelanggan yang sudah aktif
        $session = [
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
            'current_tenant_id' => $tenant->id,
            'current_store_id' => $store->id,
        ];

        // 1. Saat tenant masih aktif, cart dapat diakses
        $resActive = $this->withSession($session)->get(route('customer.cart.index'));
        $resActive->assertStatus(200);

        // 2. Tenant ditangguhkan (misal karena jatuh tempo billing)
        $tenant->update(['access_status' => Tenant::ACCESS_SUSPENDED_BILLING]);

        // 3. Request berikutnya pada cart langsung ditolak 403 dan sesi dibersihkan
        $resSuspended = $this->withSession($session)->get(route('customer.cart.index'));
        $resSuspended->assertStatus(403);
        $this->assertNull(session('current_table_id'));
    }

    public function test_suspended_tenant_is_blocked_on_webhook_orders(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture(Tenant::ACCESS_SUSPENDED);

        Config::set('services.webhooks.grabfood_secret', 'secret_test_key_123');
        Config::set('services.webhooks.default_tenant_id', null);
        Config::set('services.webhooks.default_store_id', null);

        $payload = ['id' => 'EXT-SUSPENDED-01', 'items' => []];
        $rawBody = json_encode($payload);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $rawBody . $timestamp, 'secret_test_key_123');

        $response = $this->postJson(
            "/api/tenants/{$tenant->id}/webhooks/grabfood/orders",
            $payload,
            [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ]
        );

        $response->assertStatus(403)
            ->assertJson(['message' => 'Tenant is suspended']);
    }
}
