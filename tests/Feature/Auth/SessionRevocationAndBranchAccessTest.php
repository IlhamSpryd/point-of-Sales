<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionRevocationAndBranchAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantWithStoresAndUsers(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kedai Kopi Multicabang',
            'slug' => 'kedai-multi-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store1 = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Utama',
            'code' => 'UTAMA-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $store2 = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Kedua',
            'code' => 'KEDUA-02',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => false,
        ]);

        $ownerRole = new Role();
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $kasirRole = new Role();
        $kasirRole->tenant_id = $tenant->id;
        $kasirRole->name = 'Kasir';
        $kasirRole->permissions = ['pos.order.create', 'shifts.manage'];
        $kasirRole->is_active = true;
        $kasirRole->save();

        $owner = new User();
        $owner->tenant_id = $tenant->id;
        $owner->role_id = $ownerRole->id;
        $owner->name = 'Owner Multi';
        $owner->email = 'owner.multi@example.test';
        $owner->email_normalized = 'owner.multi@example.test';
        $owner->password_hash = bcrypt('Password123!');
        $owner->is_active = true;
        $owner->email_verified_at = now();
        $owner->save();

        $kasir = new User();
        $kasir->tenant_id = $tenant->id;
        $kasir->role_id = $kasirRole->id;
        $kasir->name = 'Kasir Multi';
        $kasir->email = 'kasir.multi@example.test';
        $kasir->email_normalized = 'kasir.multi@example.test';
        $kasir->password_hash = bcrypt('Password123!');
        $kasir->is_active = true;
        $kasir->email_verified_at = now();
        $kasir->save();

        $kasir->stores()->attach([$store1->id, $store2->id], ['tenant_id' => $tenant->id]);

        return [$tenant, $owner, $kasir, $store1, $store2];
    }

    public function test_suspended_or_inactive_user_with_active_session_is_immediately_terminated(): void
    {
        [$tenant, $owner, $kasir, $store1, $store2] = $this->createTenantWithStoresAndUsers();
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        // 1. Kasir login dan memiliki sesi aktif
        $this->actingAs($kasir);
        $res1 = $this->get('/transaction');
        $res1->assertStatus(200);

        // 2. Akun kasir dinonaktifkan oleh admin (is_active = false)
        $kasir->update(['is_active' => false]);

        // Request berikutnya langsung dihentikan, logout, dan abort 403
        $res2 = $this->get('/transaction');
        $res2->assertStatus(403);
        $this->assertGuest();
    }

    public function test_unverified_user_is_blocked_by_enforce_email_verification(): void
    {
        [$tenant, $owner, $kasir, $store1, $store2] = $this->createTenantWithStoresAndUsers();
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        $owner->update(['email_verified_at' => null]);

        $response = $this->actingAs($owner)->get('/dashboard');
        $response->assertRedirect(route('verification.notice'));
    }

    public function test_branch_revocation_in_active_session_switches_or_denies_access(): void
    {
        [$tenant, $owner, $kasir, $store1, $store2] = $this->createTenantWithStoresAndUsers();
        app(TenantContext::class)->setTenantId($tenant->id);

        // 1. Kasir aktif di Cabang 1
        $this->actingAs($kasir);
        $this->withSession(['active_store_id' => $store1->id]);

        // Akses transaksi kasir sukses
        $res1 = $this->get('/transaction');
        $res1->assertStatus(200);
        $this->assertEquals($store1->id, session('active_store_id'));

        // 2. Cabang 1 dicabut dari kasir (hanya tersisa Cabang 2)
        $kasir->stores()->detach($store1->id);

        // Request berikutnya mengevaluasi ulang: StoreResolver otomatis mengalihkan ke Cabang 2
        $res2 = $this->get('/transaction');
        $res2->assertStatus(200);
        $this->assertEquals($store2->id, session('active_store_id'));

        // 3. Seluruh cabang dicabut dari kasir
        $kasir->stores()->detach($store2->id);

        // Request berikutnya: Tidak ada cabang yang dapat diakses -> no-access 403
        $res3 = $this->get('/transaction');
        $res3->assertStatus(403);
    }

    public function test_cannot_deactivate_or_delete_cashier_with_open_shift(): void
    {
        [$tenant, $owner, $kasir, $store1, $store2] = $this->createTenantWithStoresAndUsers();
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        // Kasir memiliki shift yang sedang OPEN
        Shift::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store1->id,
            'user_id' => $kasir->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $userService = app(UserService::class);

        // 1. Coba nonaktifkan kasir lewat UserService: DITOLAK
        try {
            $userService->update($kasir, ['is_active' => false]);
            $this->fail('Seharusnya gagal menonaktifkan kasir yang shift-nya masih buka.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('masih memiliki shift aktif', $e->getMessage());
        }

        // 2. Coba hapus kasir lewat UserService: DITOLAK
        try {
            $userService->delete($kasir);
            $this->fail('Seharusnya gagal menghapus kasir yang shift-nya masih buka.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('masih memiliki shift aktif', $e->getMessage());
        }
    }

    public function test_cashier_cannot_switch_branch_with_open_shift(): void
    {
        [$tenant, $owner, $kasir, $store1, $store2] = $this->createTenantWithStoresAndUsers();
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        Shift::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store1->id,
            'user_id' => $kasir->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($kasir);
        $this->withSession(['active_store_id' => $store1->id]);

        $response = $this->from('/transaction')->post(route('store.switch'), [
            'store_id' => (string) $store2->id,
        ]);

        $response->assertSessionHasErrors('store_id');
    }

    public function test_cannot_revoke_store_access_when_cashier_has_open_shift_in_that_store(): void
    {
        [$tenant, $owner, $kasir, $store1, $store2] = $this->createTenantWithStoresAndUsers();
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        Shift::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store1->id,
            'user_id' => $kasir->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $userService = app(UserService::class);

        try {
            $userService->revokeStoreAccess($kasir, $store1->id);
            $this->fail('Seharusnya gagal mencabut akses cabang yang shift-nya masih buka.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('masih memiliki shift aktif', $e->getMessage());
        }

        $userService->revokeStoreAccess($kasir, $store2->id);
        $this->assertDatabaseMissing('user_stores', [
            'tenant_id' => $tenant->id,
            'user_id' => $kasir->id,
            'store_id' => $store2->id,
        ]);
    }
}
