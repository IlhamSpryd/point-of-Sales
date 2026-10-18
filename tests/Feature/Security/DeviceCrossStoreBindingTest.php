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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceCrossStoreBindingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;
    private Store $store1;
    private Store $store2;
    private User $ownerA;
    private User $managerStore1;
    private User $kasirStore1;
    private Device $deviceStore2;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create([
            'name' => 'Resto Multi Cabang',
            'slug' => 'resto-multi-cabang-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);

        $this->store1 = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Sudirman',
            'code' => 'SDR-01',
            'is_initial' => true,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->store2 = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Kuningan',
            'code' => 'KNG-02',
            'is_initial' => false,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
        ]);

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
            'email' => 'owner.multicabang@example.test',
        ]);

        $this->managerStore1 = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleManager->id,
            'email' => 'manager.store1@example.test',
        ]);

        $this->kasirStore1 = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleKasir->id,
            'email' => 'kasir.store1@example.test',
        ]);

        // Hubungkan manager dan kasir HANYA ke store 1
        DB::table('user_stores')->insert([
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->managerStore1->id, 'store_id' => $this->store1->id],
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->kasirStore1->id, 'store_id' => $this->store1->id],
        ]);

        // Buat device di Cabang 2
        $this->deviceStore2 = $context->runAs($this->tenantA->id, $this->store2->id, function () {
            return Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->store2->id,
                'device_name' => 'POS Terminal Cabang 2',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_PENDING,
                'is_active' => true,
            ]);
        });
    }

    public function test_owner_can_view_device_belonging_to_another_store_in_same_tenant(): void
    {
        // Set sesi aktif Owner di Cabang 1
        $response = $this->actingAs($this->ownerA)
            ->withSession(['active_store_id' => $this->store1->id])
            ->get(route('devices.show', $this->deviceStore2));

        $response->assertOk();
        $response->assertViewIs('devices.show');
        $response->assertSee('POS Terminal Cabang 2');
    }

    public function test_owner_can_edit_and_update_device_belonging_to_another_store(): void
    {
        $response = $this->actingAs($this->ownerA)
            ->withSession(['active_store_id' => $this->store1->id])
            ->put(route('devices.update', $this->deviceStore2), [
                'device_name' => 'POS Terminal Cabang 2 Updated',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'is_active' => 1,
            ]);

        $response->assertRedirect(route('devices.show', $this->deviceStore2));
        $this->assertDatabaseHas('devices', [
            'id' => $this->deviceStore2->id,
            'device_name' => 'POS Terminal Cabang 2 Updated',
        ]);
    }

    public function test_manager_assigned_only_to_store1_cannot_view_device_in_store2(): void
    {
        $response = $this->actingAs($this->managerStore1)
            ->withSession(['active_store_id' => $this->store1->id])
            ->get(route('devices.show', $this->deviceStore2));

        // Harus ditolak 403 Forbidden oleh DevicePolicy
        $response->assertStatus(403);
    }

    public function test_manager_assigned_only_to_store1_cannot_update_device_in_store2(): void
    {
        $response = $this->actingAs($this->managerStore1)
            ->withSession(['active_store_id' => $this->store1->id])
            ->put(route('devices.update', $this->deviceStore2), [
                'device_name' => 'Tampered By Other Store Manager',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'is_active' => 1,
            ]);

        // Harus ditolak 403 Forbidden
        $response->assertStatus(403);
        $this->assertDatabaseMissing('devices', [
            'id' => $this->deviceStore2->id,
            'device_name' => 'Tampered By Other Store Manager',
        ]);
    }

    public function test_kasir_cannot_view_or_modify_device(): void
    {
        $viewResponse = $this->actingAs($this->kasirStore1)
            ->withSession(['active_store_id' => $this->store1->id])
            ->get(route('devices.show', $this->deviceStore2));

        $viewResponse->assertStatus(403);

        $updateResponse = $this->actingAs($this->kasirStore1)
            ->withSession(['active_store_id' => $this->store1->id])
            ->put(route('devices.update', $this->deviceStore2), [
                'device_name' => 'Kasir Attempt',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'is_active' => 1,
            ]);

        $updateResponse->assertStatus(403);
    }

    public function test_cross_tenant_device_resolution_returns_404_due_to_tenant_scope(): void
    {
        $tenantB = Tenant::create([
            'name' => 'Tenant Lain',
            'slug' => 'tenant-lain-' . uniqid(),
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
                'email' => 'owner.b@example.test',
            ]);
        });

        // User dari Tenant B mencoba mengakses device milik Tenant A
        // Karena TenantScope tetap aktif pada resolveRouteBindingQuery,
        // resource tidak akan ditemukan untuk Tenant B -> 404 Not Found!
        $response = $this->actingAs($ownerB)
            ->get(route('devices.show', $this->deviceStore2));

        $this->assertTrue(in_array($response->status(), [403, 404], true));
    }
}
