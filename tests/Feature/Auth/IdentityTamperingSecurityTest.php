<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\ShiftService;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityTamperingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantWithRoles(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kedai Kopi Aman',
            'slug' => 'kedai-aman-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang 1',
            'code' => 'CB-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $ownerRole = new Role;
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $kasirRole = new Role;
        $kasirRole->tenant_id = $tenant->id;
        $kasirRole->name = 'Kasir';
        $kasirRole->permissions = ['pos.order.create', 'shifts.manage'];
        $kasirRole->is_active = true;
        $kasirRole->save();

        $owner = new User;
        $owner->tenant_id = $tenant->id;
        $owner->role_id = $ownerRole->id;
        $owner->name = 'Owner Aman';
        $owner->email = 'owner@aman.test';
        $owner->email_normalized = 'owner@aman.test';
        $owner->password_hash = bcrypt('Password123!');
        $owner->is_active = true;
        $owner->email_verified_at = now();
        $owner->save();

        return [$tenant, $owner, $kasirRole, $ownerRole, $store];
    }

    public function test_invitation_activation_ignores_injected_tenant_id_role_id_and_store_ids(): void
    {
        [$tenant, $owner, $kasirRole, $ownerRole, $store] = $this->createTenantWithRoles();
        app(TenantContext::class)->setTenantId($tenant->id);

        $service = app(UserInvitationService::class);
        $invitation = $service->issueInvitation($owner, [
            'name' => 'Calon Kasir',
            'email' => 'calon.kasir@aman.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$store->id],
        ]);

        $plaintextToken = $invitation->plaintextToken;

        // Attacker mencoba menyuntikkan tenant_id=9999, role_id=ownerRole, dan is_admin=1 pada payload aktivasi
        $response = $this->post("/invitations/{$plaintextToken}/accept", [
            'password' => 'HackerPassword123!',
            'password_confirmation' => 'HackerPassword123!',
            'tenant_id' => 9999,
            'role_id' => $ownerRole->id, // Injeksi Owner
            'is_admin' => 1,
            'store_ids' => [9999, 8888],
        ]);

        $response->assertRedirect(route('dashboard'));

        $activatedUser = User::withoutGlobalScopes()->where('email_normalized', 'calon.kasir@aman.test')->first();
        $this->assertNotNull($activatedUser);

        // Nilai server-side TIDAK terpengaruh oleh injeksi klien
        $this->assertEquals($tenant->id, $activatedUser->tenant_id);
        $this->assertEquals($kasirRole->id, $activatedUser->role_id);
        $this->assertNotEquals($ownerRole->id, $activatedUser->role_id);

        // Cabang tetap sesuai yang ditetapkan server
        $this->assertEquals([$store->id], $activatedUser->stores()->pluck('stores.id')->all());
    }

    public function test_deferred_cashier_pin_enforcement_blocks_shift_opening_until_pin_is_set(): void
    {
        [$tenant, $owner, $kasirRole, $ownerRole, $store] = $this->createTenantWithRoles();
        app(TenantContext::class)->setTenantId($tenant->id);

        $service = app(UserInvitationService::class);
        $invitation = $service->issueInvitation($owner, [
            'name' => 'Kasir Tanpa PIN',
            'email' => 'kasir.nopin@aman.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$store->id],
        ]);

        $kasir = $service->acceptInvitation($invitation->plaintextToken, [
            'password' => 'Pass12345!',
        ]);

        // Kasir baru aktif tanpa PIN
        $this->assertNull($kasir->pin_hash);

        // 1. Coba buka shift melalui ShiftService: DITOLAK karena pin_hash belum disetel
        $shiftService = app(ShiftService::class);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Kasir wajib menyetel PIN Kasir sebelum dapat membuka shift.');
        $shiftService->openShift($kasir->id, 100000);
    }

    public function test_cashier_can_open_shift_after_setting_pin(): void
    {
        [$tenant, $owner, $kasirRole, $ownerRole, $store] = $this->createTenantWithRoles();
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $service = app(UserInvitationService::class);
        $invitation = $service->issueInvitation($owner, [
            'name' => 'Kasir Berpin',
            'email' => 'kasir.berpin@aman.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$store->id],
        ]);

        $kasir = $service->acceptInvitation($invitation->plaintextToken, [
            'password' => 'Pass12345!',
        ]);

        // Setel PIN 6 digit
        $kasir->update([
            'pin_hash' => bcrypt('123456'),
        ]);

        $this->assertNotNull($kasir->pin_hash);

        // Buka shift sekarang berhasil
        $shiftService = app(ShiftService::class);
        $shift = $shiftService->openShift($kasir->id, 50000);

        $this->assertNotNull($shift);
        $this->assertEquals('open', $shift->status);
        $this->assertEquals(50000, $shift->opening_balance);
    }
}
