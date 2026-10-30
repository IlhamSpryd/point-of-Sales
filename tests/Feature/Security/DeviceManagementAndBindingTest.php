<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Device;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceManagementAndBindingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Store $storeA;

    private User $ownerA;

    private User $managerA;

    private User $kasirA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create([
            'name' => 'Resto Sedap Rasa',
            'slug' => 'resto-sedap-rasa',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Sedap Rasa Thamrin',
            'code' => 'STR-THM-01',
            'is_initial' => true,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
        ]);
        $context->setStoreId($this->storeA->id);

        $roleOwner = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $roleManager = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Manager',
            'permissions' => ['devices.*'],
        ]);

        $roleKasir = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create'],
        ]);

        $this->ownerA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleOwner->id,
            'email' => 'owner@sedaprasa.com',
        ]);

        $this->managerA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleManager->id,
            'email' => 'manager@sedaprasa.com',
        ]);

        $this->kasirA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleKasir->id,
            'email' => 'kasir@sedaprasa.com',
        ]);

        DB::table('user_stores')->insert([
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->ownerA->id, 'store_id' => $this->storeA->id],
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->managerA->id, 'store_id' => $this->storeA->id],
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->kasirA->id, 'store_id' => $this->storeA->id],
        ]);
    }

    public function test_owner_and_manager_can_register_device_and_issue_pairing_token(): void
    {
        $response = $this->actingAs($this->ownerA)->post(route('devices.store'), [
            'store_id' => $this->storeA->id,
            'device_name' => 'Kasir Utama Kasir 1',
            'device_type' => Device::TYPE_POS_TERMINAL,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $device = Device::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('device_name', 'Kasir Utama Kasir 1')
            ->first();

        $this->assertNotNull($device);
        $this->assertEquals(Device::STATUS_PENDING, $device->status);
        $this->assertNotEmpty($device->pairing_token);
        $this->assertEquals(12, strlen($device->pairing_token));
        $this->assertTrue($device->pairing_token_expires_at->isFuture());

        // Audit log registration tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'device.registered',
            'user_id' => $this->ownerA->id,
            'subject_id' => $device->id,
        ]);
    }

    public function test_kasir_is_forbidden_from_managing_devices(): void
    {
        $response = $this->actingAs($this->kasirA)->get(route('devices.index'));
        $response->assertForbidden();

        $createResponse = $this->actingAs($this->kasirA)->post(route('devices.store'), [
            'store_id' => $this->storeA->id,
            'device_name' => 'Unauthorized Tablet',
            'device_type' => Device::TYPE_WAITER_TABLET,
        ]);
        $createResponse->assertForbidden();
    }

    public function test_cannot_register_device_to_another_tenants_store(): void
    {
        $tenantB = Tenant::create([
            'name' => 'Warung Sebelah',
            'slug' => 'warung-sebelah',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $storeB = app(TenantContext::class)->runAs($tenantB->id, null, function () use ($tenantB) {
            return Store::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Cabang Sebelah',
                'is_initial' => true,
                'is_active' => true,
            ]);
        });

        // Owner A mencoba mendaftarkan device ke Store B
        $response = $this->actingAs($this->ownerA)->post(route('devices.store'), [
            'store_id' => $storeB->id,
            'device_name' => 'Device Nyasar',
            'device_type' => Device::TYPE_POS_TERMINAL,
        ]);

        $response->assertSessionHasErrors(['store_id']);
    }

    public function test_physical_pos_terminal_can_pair_successfully_using_token_and_fingerprint(): void
    {
        $context = app(TenantContext::class);
        $device = $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Terminal POS 01',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'pairing_token' => 'VALIDPAIR123',
                'pairing_token_expires_at' => Carbon::now()->addHours(24),
                'status' => Device::STATUS_PENDING,
                'is_active' => true,
            ]);
        });

        $pairResponse = $this->postJson(route('api.devices.pair'), [
            'pairing_token' => 'VALIDPAIR123',
            'device_fingerprint' => 'BROWSER-FP-WIN10-CHROME-89823472',
            'metadata' => [
                'browser' => 'Chrome 122',
                'screen' => '1920x1080',
            ],
        ]);

        $pairResponse->assertOk();
        $pairResponse->assertJson([
            'success' => true,
            'device' => [
                'id' => $device->id,
                'status' => Device::STATUS_PAIRED,
            ],
        ]);

        $freshDevice = $device->fresh();
        $this->assertEquals(Device::STATUS_PAIRED, $freshDevice->status);
        $this->assertEquals('BROWSER-FP-WIN10-CHROME-89823472', $freshDevice->device_fingerprint);
        // Token pairing sekali pakai harus dibersihkan setelah berhasil pairing
        $this->assertNull($freshDevice->pairing_token);
        $this->assertNull($freshDevice->pairing_token_expires_at);
        $this->assertNotNull($freshDevice->last_seen_at);

        // Audit log pairing tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'device.paired',
            'subject_id' => $device->id,
        ]);
    }

    public function test_pairing_fails_with_invalid_or_expired_token(): void
    {
        // 1. Token salah
        $responseInvalid = $this->postJson(route('api.devices.pair'), [
            'pairing_token' => 'TOKENSALAH99',
            'device_fingerprint' => 'ANY-FP-TEST',
        ]);

        $responseInvalid->assertStatus(422);
        $responseInvalid->assertJson(['success' => false]);

        // 2. Token kedaluwarsa
        $context = app(TenantContext::class);
        $expiredDevice = $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Terminal POS Expired',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'pairing_token' => 'EXPIREDTOK12',
                'pairing_token_expires_at' => Carbon::now()->subMinutes(10),
                'status' => Device::STATUS_PENDING,
                'is_active' => true,
            ]);
        });

        $responseExpired = $this->postJson(route('api.devices.pair'), [
            'pairing_token' => 'EXPIREDTOK12',
            'device_fingerprint' => 'ANY-FP-TEST',
        ]);

        $responseExpired->assertStatus(422);
        $responseExpired->assertJsonFragment(['message' => 'Pairing code has expired. Please request a new code from the dashboard.']);
    }

    public function test_owner_and_manager_can_revoke_paired_device(): void
    {
        $context = app(TenantContext::class);
        $device = $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Terminal Kasir Bar',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_PAIRED,
                'device_fingerprint' => 'FP-TO-REVOKE',
                'is_active' => true,
            ]);
        });

        $response = $this->actingAs($this->managerA)->post(route('devices.revoke', $device));

        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('success');

        $freshDevice = $device->fresh();
        $this->assertEquals(Device::STATUS_REVOKED, $freshDevice->status);
        $this->assertFalse((bool) $freshDevice->is_active);
        $this->assertNull($freshDevice->device_fingerprint);

        // Audit log revokasi tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'device.revoked',
            'subject_id' => $device->id,
        ]);
    }

    public function test_regenerate_token_issues_new_code_and_resets_status_to_pending(): void
    {
        $context = app(TenantContext::class);
        $device = $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Terminal Replaced',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_REVOKED,
                'is_active' => false,
            ]);
        });

        $response = $this->actingAs($this->ownerA)->post(route('devices.regenerate-token', $device));

        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('success');

        $freshDevice = $device->fresh();
        $this->assertEquals(Device::STATUS_PENDING, $freshDevice->status);
        $this->assertNotEmpty($freshDevice->pairing_token);
        $this->assertTrue($freshDevice->pairing_token_expires_at->isFuture());

        // Audit log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'device.token_regenerated',
            'subject_id' => $device->id,
        ]);
    }

    public function test_cross_tenant_device_isolation(): void
    {
        $tenantB = Tenant::create([
            'name' => 'Kedai Kopi B',
            'slug' => 'kedai-kopi-b',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context = app(TenantContext::class);

        $roleOwnerB = $context->runAs($tenantB->id, null, function () use ($tenantB) {
            return Role::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Owner',
                'permissions' => ['*'],
            ]);
        });

        $ownerB = $context->runAs($tenantB->id, null, function () use ($tenantB, $roleOwnerB) {
            return User::factory()->create([
                'tenant_id' => $tenantB->id,
                'role_id' => $roleOwnerB->id,
            ]);
        });
        $deviceA = $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Private POS A',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_PENDING,
            ]);
        });

        // Owner B dilarang melihat atau mengubah device milik Tenant A (ScopedToTenant -> 404 / Policy -> 403)
        $viewResponse = $this->actingAs($ownerB)->get(route('devices.show', $deviceA));
        $this->assertTrue(in_array($viewResponse->status(), [403, 404], true));

        $updateResponse = $this->actingAs($ownerB)->put(route('devices.update', $deviceA), [
            'device_name' => 'Hacked Name',
            'device_type' => Device::TYPE_POS_TERMINAL,
            'is_active' => 1,
        ]);
        $this->assertTrue(in_array($updateResponse->status(), [403, 404], true));
    }

    public function test_owner_can_delete_device(): void
    {
        $context = app(TenantContext::class);
        $device = $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Terminal Rusak',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_REVOKED,
                'is_active' => false,
            ]);
        });

        $response = $this->actingAs($this->ownerA)->delete(route('devices.destroy', $device));

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('devices', [
            'id' => $device->id,
            'tenant_id' => $this->tenantA->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'device.deleted',
            'subject_id' => $device->id,
        ]);
    }
}
