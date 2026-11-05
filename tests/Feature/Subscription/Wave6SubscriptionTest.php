<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Modules\Subscription\Exceptions\PlanLimitExceededException;
use App\Modules\Subscription\Services\EntitlementEnforcementService;
use App\Modules\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Wave6SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Plan $starterPlan;

    protected EntitlementEnforcementService $entitlementService;

    protected SubscriptionLifecycleService $lifecycleService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Cache::flush();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

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

        $this->entitlementService = app(EntitlementEnforcementService::class);
        $this->lifecycleService = app(SubscriptionLifecycleService::class);

        // Buat atau ambil Paket Starter dengan kuota 1 cabang (max_stores = 1)
        $this->starterPlan = Plan::firstOrCreate(
            ['code' => 'starter'],
            [
                'name' => 'Starter Plan',
                'description' => 'Paket pemula 1 toko',
                'price' => 0,
                'billing_period' => 'monthly',
                'is_active' => true,
            ]
        );

        PlanEntitlement::updateOrCreate(
            [
                'plan_id' => $this->starterPlan->id,
                'feature_code' => EntitlementEnforcementService::QUOTA_MAX_STORES,
            ],
            [
                'value' => '1',
            ]
        );

        $this->tenant->update([
            'plan_code' => 'starter',
            'status' => 'active',
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->starterPlan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /**
     * T-SUB-01: Tenant berpaket Starter (kuota 1 cabang) ditolak saat mencoba membuat cabang kedua via PlanLimitExceededException.
     */
    public function test_t_sub_01_starter_tenant_exceeding_store_quota_throws_plan_limit_exceeded_exception(): void
    {
        // Tenant sudah memiliki 1 toko (this->store). Kuota Starter = 1.
        $this->assertEquals(1, Store::where('tenant_id', $this->tenant->id)->count());

        // Pemeriksaan pembuatan cabang kedua wajib melempar PlanLimitExceededException
        $this->expectException(PlanLimitExceededException::class);
        $this->expectExceptionMessage('Batas kuota cabang fisik (store)');

        $this->entitlementService->assertCanCreateStore($this->tenant->id);
    }

    /**
     * T-SUB-02: Tenant berstatus suspended otomatis diblokir dari endpoint operasional kasir dan API dengan respon HTTP 403 Forbidden (error: TENANT_SUSPENDED).
     */
    public function test_t_sub_02_suspended_tenant_is_blocked_from_operational_endpoints_with_http_403(): void
    {
        // 1. Ubah status tenant menjadi suspended melalui SubscriptionLifecycleService
        /** @var TenantSubscription $subscription */
        $subscription = TenantSubscription::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->lifecycleService->transitionStatus($subscription, 'suspended', 'Gagal bayar invoice bulanan');

        $this->tenant->refresh();
        $this->assertTrue($this->tenant->isSuspended());

        // 2. Akses endpoint API menggunakan Sanctum token dengan header accept json
        Sanctum::actingAs($this->user, ['orders:read']);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/orders');

        // 3. Verifikasi HTTP 403 Forbidden dan error: TENANT_SUSPENDED
        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'TENANT_SUSPENDED',
        ]);
    }
}
