<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Device;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchCrudAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Store $initialStoreA;

    private User $ownerA;

    private User $managerA;

    private User $kasirA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create([
            'name' => 'Kopi Sejahtera',
            'slug' => 'kopi-sejahtera',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_plan' => 'pro',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);

        $this->initialStoreA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Pusat Senopati',
            'code' => 'STR-001',
            'is_initial' => true,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
            'address' => 'Jl. Senopati No. 1, Jakarta Selatan',
        ]);
        $context->setStoreId($this->initialStoreA->id);

        $roleOwner = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $roleManager = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Manager',
            'permissions' => ['devices.view', 'devices.create'],
        ]);

        $roleKasir = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create'],
        ]);

        $this->ownerA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleOwner->id,
            'email' => 'owner@kopisejahtera.id',
        ]);

        $this->managerA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleManager->id,
            'email' => 'manager@kopisejahtera.id',
        ]);

        $this->kasirA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleKasir->id,
            'email' => 'kasir@kopisejahtera.id',
        ]);

        DB::table('user_stores')->insert([
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->ownerA->id, 'store_id' => $this->initialStoreA->id],
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->managerA->id, 'store_id' => $this->initialStoreA->id],
            ['tenant_id' => $this->tenantA->id, 'user_id' => $this->kasirA->id, 'store_id' => $this->initialStoreA->id],
        ]);
    }

    public function test_owner_can_view_branch_list_and_create_store_with_audit_trail(): void
    {
        $response = $this->actingAs($this->ownerA)->get(route('stores.index'));
        $response->assertOk();
        $response->assertSee('Cabang Pusat Senopati');
        $response->assertSee('Cabang Utama');

        $createResponse = $this->actingAs($this->ownerA)->post(route('stores.store'), [
            'name' => 'Cabang Bintaro Sektor 9',
            'timezone' => 'Asia/Jakarta',
            'address' => 'Jl. Bintaro Utama Blok J',
            'phone' => '081299887766',
        ]);

        $createResponse->assertRedirect(route('stores.index'));
        $createResponse->assertSessionHas('success');

        $newStore = Store::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->where('name', 'Cabang Bintaro Sektor 9')
            ->first();

        $this->assertNotNull($newStore);
        $this->assertFalse((bool) $newStore->is_initial);
        $this->assertNotEmpty($newStore->code);

        // Owner otomatis terhubung di user_stores
        $this->assertDatabaseHas('user_stores', [
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->ownerA->id,
            'store_id' => $newStore->id,
        ]);

        // Audit log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'store.created',
            'user_id' => $this->ownerA->id,
            'subject_id' => $newStore->id,
        ]);
    }

    public function test_owner_can_update_store(): void
    {
        $store = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Kemang',
            'code' => 'STR-002',
            'is_initial' => false,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
        ]);

        $response = $this->actingAs($this->ownerA)->put(route('stores.update', $store), [
            'name' => 'Cabang Kemang Raya Baru',
            'timezone' => 'Asia/Jakarta',
            'address' => 'Jl. Kemang Raya No. 12',
            'phone' => '0217198888',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('stores.index'));
        $this->assertEquals('Cabang Kemang Raya Baru', $store->fresh()->name);

        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'store.updated',
            'subject_id' => $store->id,
        ]);
    }

    public function test_non_owner_is_forbidden_from_managing_branches(): void
    {
        // Manager ditolak
        $response = $this->actingAs($this->managerA)->get(route('stores.index'));
        $response->assertForbidden();

        $createResponse = $this->actingAs($this->managerA)->post(route('stores.store'), [
            'name' => 'Cabang Bajakan',
            'timezone' => 'Asia/Jakarta',
        ]);
        $createResponse->assertForbidden();

        // Kasir ditolak
        $kasirResponse = $this->actingAs($this->kasirA)->get(route('stores.index'));
        $kasirResponse->assertForbidden();
    }

    public function test_cross_tenant_branch_access_is_strictly_forbidden(): void
    {
        $tenantB = Tenant::create([
            'name' => 'Teh Melati',
            'slug' => 'teh-melati',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context = app(TenantContext::class);

        $storeB = $context->runAs($tenantB->id, null, function () use ($tenantB) {
            return Store::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Cabang Melati 1',
                'is_initial' => true,
                'is_active' => true,
            ]);
        });

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

        // Owner B tidak boleh update cabang Tenant A (ScopedToTenant menolak lookup -> 404 / Policy menolak -> 403)
        $response = $this->actingAs($ownerB)->put(route('stores.update', $this->initialStoreA), [
            'name' => 'Hacked Name',
            'timezone' => 'Asia/Jakarta',
            'is_active' => 1,
        ]);

        $this->assertTrue(
            in_array($response->status(), [403, 404], true),
            "Cross-tenant branch update must return 403 or 404, received {$response->status()}"
        );
        $this->assertEquals('Cabang Pusat Senopati', $this->initialStoreA->fresh()->name);
    }

    public function test_initial_store_cannot_be_deleted(): void
    {
        $response = $this->actingAs($this->ownerA)->delete(route('stores.destroy', $this->initialStoreA));

        // StorePolicy menolak penghapusan initial store (403 Forbidden)
        $response->assertForbidden();

        $this->assertDatabaseHas('stores', [
            'id' => $this->initialStoreA->id,
            'tenant_id' => $this->tenantA->id,
            'deleted_at' => null,
        ]);
    }

    public function test_branch_with_open_shift_cannot_be_deleted(): void
    {
        $branch = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang BSD',
            'code' => 'STR-003',
            'is_initial' => false,
            'is_active' => true,
        ]);

        // Buat open shift di cabang BSD
        app(TenantContext::class)->runAs($this->tenantA->id, $branch->id, function () use ($branch) {
            Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $branch->id,
                'user_id' => $this->kasirA->id,
                'opening_balance' => 100000,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });

        $response = $this->actingAs($this->ownerA)->delete(route('stores.destroy', $branch));

        $response->assertRedirect(route('stores.index'));
        $response->assertSessionHas('error', 'Tidak dapat menghapus cabang yang masih memiliki shift kasir aktif.');

        $this->assertDatabaseHas('stores', [
            'id' => $branch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_branch_with_paired_active_device_cannot_be_deleted(): void
    {
        $branch = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Kelapa Gading',
            'code' => 'STR-004',
            'is_initial' => false,
            'is_active' => true,
        ]);

        // Buat paired device aktif di cabang
        app(TenantContext::class)->runAs($this->tenantA->id, $branch->id, function () use ($branch) {
            Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $branch->id,
                'device_name' => 'POS Terminal 1',
                'device_code' => 'DEV-KG-01',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_PAIRED,
                'device_fingerprint' => 'fp-unique-123',
                'is_active' => true,
            ]);
        });

        $response = $this->actingAs($this->ownerA)->delete(route('stores.destroy', $branch));

        $response->assertRedirect(route('stores.index'));
        $response->assertSessionHas('error', 'Tidak dapat menghapus cabang yang masih memiliki perangkat POS terhubung (paired).');

        $this->assertDatabaseHas('stores', [
            'id' => $branch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_eligible_branch_can_be_deleted_cleanly_with_audit_trail(): void
    {
        $branch = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Depok',
            'code' => 'STR-005',
            'is_initial' => false,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->ownerA->id,
            'store_id' => $branch->id,
        ]);

        $response = $this->actingAs($this->ownerA)->delete(route('stores.destroy', $branch));

        $response->assertRedirect(route('stores.index'));
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('stores', [
            'id' => $branch->id,
            'tenant_id' => $this->tenantA->id,
        ]);

        // Ikatan user_stores dibersihkan
        $this->assertDatabaseMissing('user_stores', [
            'tenant_id' => $this->tenantA->id,
            'store_id' => $branch->id,
        ]);

        // Audit log penghapusan cabang tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'store.deleted',
            'subject_id' => $branch->id,
        ]);
    }
}
