<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Device;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\DeviceService;
use App\Services\EntitlementService;
use App\Services\StoreService;
use App\Services\UserInvitationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantEntitlementAndQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store;

    private User $owner;

    private EntitlementService $entitlementService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->entitlementService = app(EntitlementService::class);

        $this->tenant = Tenant::create([
            'name' => 'Kopi Monetisasi',
            'slug' => 'kopi-monetisasi',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Pusat Monetisasi',
            'code' => 'STR-MNT-01',
            'is_initial' => true,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
        ]);
        $context->setStoreId($this->store->id);

        $roleOwner = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleOwner->id,
            'email' => 'owner@kopimonetisasi.id',
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_trial_plan_can_access_advanced_features_during_trial(): void
    {
        // 1. Service check
        $this->assertTrue($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));
        $this->assertTrue($this->entitlementService->canAccessFeature($this->tenant->id, 'omnichannel'));
        $this->assertTrue($this->entitlementService->canAccessFeature($this->tenant->id, 'async_export'));

        // 2. HTTP Route check — Restock Forecast endpoint allowed
        $response = $this->actingAs($this->owner)->getJson(route('analytics.restock-forecasts'));
        $response->assertOk();
    }

    public function test_starter_plan_is_forbidden_from_advanced_features(): void
    {
        // Ubah plan tenant ke starter
        $this->tenant->update(['subscription_plan' => 'starter']);
        $this->entitlementService->clearCache($this->tenant->id);

        $this->assertFalse($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));
        $this->assertFalse($this->entitlementService->canAccessFeature($this->tenant->id, 'omnichannel'));
        $this->assertFalse($this->entitlementService->canAccessFeature($this->tenant->id, 'async_export'));

        // Akses API restock forecast harus 403 Forbidden dengan kode ENTITLEMENT_FORBIDDEN
        $response = $this->actingAs($this->owner)->getJson(route('analytics.restock-forecasts'));
        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'code' => 'ENTITLEMENT_FORBIDDEN',
            'feature' => 'restock_forecast',
        ]);

        // Akses Web omnichannel (channel mapping) harus 403 Forbidden
        $webResponse = $this->actingAs($this->owner)->get(route('integrations.channel-mapping'));
        $webResponse->assertForbidden();
    }

    public function test_tenant_override_grants_access_to_feature_on_starter_plan(): void
    {
        $this->tenant->update(['subscription_plan' => 'starter']);
        $this->entitlementService->clearCache($this->tenant->id);

        // Sebelum override: dilarang
        $this->assertFalse($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));

        // Berikan override khusus untuk tenant
        app(TenantContext::class)->runAs($this->tenant->id, null, function () {
            TenantEntitlementOverride::create([
                'tenant_id' => $this->tenant->id,
                'feature_code' => 'restock_forecast',
                'feature_type' => 'boolean',
                'value' => 'true',
                'reason' => 'Dispensasi promo Ramadan',
            ]);
        });

        $this->entitlementService->clearCache($this->tenant->id);

        // Setelah override: diizinkan
        $this->assertTrue($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));

        $response = $this->actingAs($this->owner)->getJson(route('analytics.restock-forecasts'));
        $response->assertOk();
    }

    public function test_expired_override_does_not_grant_access(): void
    {
        $this->tenant->update(['subscription_plan' => 'starter']);

        app(TenantContext::class)->runAs($this->tenant->id, null, function () {
            TenantEntitlementOverride::create([
                'tenant_id' => $this->tenant->id,
                'feature_code' => 'restock_forecast',
                'feature_type' => 'boolean',
                'value' => 'true',
                'expires_at' => Carbon::now()->subHour(),
                'reason' => 'Trial tambahan 1 hari yang sudah lewat',
            ]);
        });

        $this->entitlementService->clearCache($this->tenant->id);

        $this->assertFalse($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));

        $response = $this->actingAs($this->owner)->getJson(route('analytics.restock-forecasts'));
        $response->assertForbidden();
    }

    public function test_quota_max_stores_prevents_creating_branch_beyond_limit(): void
    {
        $this->tenant->update(['subscription_plan' => 'starter']); // max_stores = 1
        $this->entitlementService->clearCache($this->tenant->id);

        $this->assertEquals(1, $this->entitlementService->getQuota($this->tenant->id, 'max_stores'));

        $storeService = app(StoreService::class);

        // Cabang pertama (initialStore) sudah ada, sehingga kuota (1) sudah tercapai
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Batas kuota cabang (1) untuk paket langganan Anda telah tercapai');

        $storeService->store([
            'name' => 'Cabang Kedua Melebihi Kuota',
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    public function test_quota_max_devices_prevents_registering_devices_beyond_limit(): void
    {
        $this->tenant->update(['subscription_plan' => 'starter']); // max_devices_per_store = 2
        $this->entitlementService->clearCache($this->tenant->id);

        $this->assertEquals(2, $this->entitlementService->getQuota($this->tenant->id, 'max_devices_per_store'));

        $deviceService = app(DeviceService::class);

        // Daftarkan 2 devices pertama
        $deviceService->registerDevice($this->store, [
            'device_name' => 'Device 1',
            'device_type' => Device::TYPE_POS_TERMINAL,
        ]);

        $deviceService->registerDevice($this->store, [
            'device_name' => 'Device 2',
            'device_type' => Device::TYPE_POS_TERMINAL,
        ]);

        // Percobaan device ke-3 harus melempar DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Batas kuota perangkat (2) untuk cabang ini telah tercapai');

        $deviceService->registerDevice($this->store, [
            'device_name' => 'Device 3 Melebihi Kuota',
            'device_type' => Device::TYPE_POS_TERMINAL,
        ]);
    }

    public function test_quota_max_employees_prevents_inviting_beyond_limit(): void
    {
        $this->tenant->update(['subscription_plan' => 'starter']); // max_employees = 5
        $this->entitlementService->clearCache($this->tenant->id);

        $this->assertEquals(5, $this->entitlementService->getQuota($this->tenant->id, 'max_employees'));

        $roleStaff = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff',
            'permissions' => ['orders.create'],
        ]);

        // Buat 4 user tambahan (total 5 termasuk owner)
        for ($i = 1; $i <= 4; $i++) {
            User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $roleStaff->id,
                'email' => "staff{$i}@kopimonetisasi.id",
            ]);
        }

        $invitationService = app(UserInvitationService::class);

        // Percobaan mengundang staf ke-6 harus ditolak
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Batas kuota karyawan (5) untuk paket langganan Anda telah tercapai');

        $invitationService->issueInvitation($this->owner, [
            'name' => 'Staff Keenam',
            'email' => 'staff6@kopimonetisasi.id',
            'role_id' => $roleStaff->id,
            'store_ids' => [$this->store->id],
        ]);
    }

    public function test_upgrading_plan_via_subscription_increases_quotas_and_enables_features(): void
    {
        $this->tenant->update(['subscription_plan' => 'starter']);
        $this->entitlementService->clearCache($this->tenant->id);

        $this->assertEquals(1, $this->entitlementService->getQuota($this->tenant->id, 'max_stores'));
        $this->assertFalse($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));

        // Upgrade ke plan Pro via TenantSubscription aktif
        $proPlan = Plan::where('code', 'pro')->firstOrFail();

        app(TenantContext::class)->runAs($this->tenant->id, null, function () use ($proPlan) {
            TenantSubscription::create([
                'tenant_id' => $this->tenant->id,
                'plan_id' => $proPlan->id,
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);
        });

        $this->entitlementService->clearCache($this->tenant->id);

        // Kuota dan fitur sekarang mengikuti paket Pro
        $this->assertEquals(5, $this->entitlementService->getQuota($this->tenant->id, 'max_stores'));
        $this->assertTrue($this->entitlementService->canAccessFeature($this->tenant->id, 'restock_forecast'));

        // Cabang kedua kini dapat dibuat dengan sukses
        $storeService = app(StoreService::class);
        $newStore = $storeService->store([
            'name' => 'Cabang Kedua Pro Plan',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->assertNotNull($newStore);
        $this->assertEquals('Cabang Kedua Pro Plan', $newStore->name);
    }
}
