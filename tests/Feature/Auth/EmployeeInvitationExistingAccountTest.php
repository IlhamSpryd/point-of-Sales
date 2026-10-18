<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Context\TenantContext;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeInvitationExistingAccountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantWithStoreAndRoles(string $name, string $ownerEmail): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => "{$name} Pusat",
            'code' => 'STORE-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $ownerRole = new Role();
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $staffRole = new Role();
        $staffRole->tenant_id = $tenant->id;
        $staffRole->name = 'Staff';
        $staffRole->permissions = ['pos.order.create'];
        $staffRole->is_active = true;
        $staffRole->save();

        $owner = new User();
        $owner->tenant_id = $tenant->id;
        $owner->role_id = $ownerRole->id;
        $owner->name = "Owner {$name}";
        $owner->email = $ownerEmail;
        $owner->email_normalized = strtolower($ownerEmail);
        $owner->password_hash = bcrypt('Password123!');
        $owner->is_active = true;
        $owner->email_verified_at = now();
        $owner->save();

        return [$tenant, $owner, $staffRole, $store];
    }

    public function test_cross_tenant_active_account_invitation_is_privacy_preserving_and_blocked(): void
    {
        // Tenant A memiliki kasir aktif: 'kasir.shared@example.test'
        [$tenantA, $ownerA, $roleA, $storeA] = $this->createTenantWithStoreAndRoles('Kafe Alpha', 'owner.a@example.test');
        $kasirA = new User();
        $kasirA->tenant_id = $tenantA->id;
        $kasirA->role_id = $roleA->id;
        $kasirA->name = 'Kasir Alpha';
        $kasirA->email = 'kasir.shared@example.test';
        $kasirA->email_normalized = 'kasir.shared@example.test';
        $kasirA->password_hash = bcrypt('Pass123!');
        $kasirA->is_active = true;
        $kasirA->email_verified_at = now();
        $kasirA->save();

        // Tenant B mencoba mengundang email yang sama ('kasir.shared@example.test')
        [$tenantB, $ownerB, $roleB, $storeB] = $this->createTenantWithStoreAndRoles('Kafe Beta', 'owner.b@example.test');
        app(TenantContext::class)->setTenantId($tenantB->id);

        $response = $this->actingAs($ownerB)->post('/users-invitations', [
            'name' => 'Calon Kasir Beta',
            'email' => 'kasir.shared@example.test',
            'role_id' => $roleB->id,
            'store_ids' => [$storeB->id],
        ]);

        // 1. Anti-Enumerasi: Pengundang B menerima respons generik sukses tanpa kebocoran eksistensi akun
        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('success');

        // 2. Di database: Status internal tercatat sebagai 'blocked_cross_tenant'
        $invitation = UserInvitation::where('tenant_id', $tenantB->id)
            ->where('email_normalized', 'kasir.shared@example.test')
            ->first();

        $this->assertNotNull($invitation);
        $this->assertEquals(UserInvitation::STATUS_BLOCKED_CROSS_TENANT, $invitation->status);

        // 3. Email pemberitahuan keamanan dikirim ke pemilik email via EmailOutbox
        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenantB->id,
            'recipient_email' => 'kasir.shared@example.test',
            'event_type' => 'cross_tenant_invitation_blocked',
        ]);

        // 4. Upaya aktivasi langsung diblokir dengan 409 Conflict
        $testPlaintextToken = Str::random(64);
        $invitation->update(['token_hash' => hash('sha256', $testPlaintextToken)]);

        $service = app(UserInvitationService::class);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Akun dengan email ini sedang aktif pada organisasi lain.');
        $service->acceptInvitation($testPlaintextToken, ['password' => 'NewPassword123!']);
    }

    public function test_same_tenant_invitation_for_existing_active_user_throws_validation_error(): void
    {
        [$tenantA, $ownerA, $roleA, $storeA] = $this->createTenantWithStoreAndRoles('Kafe Alpha', 'owner.a@example.test');
        app(TenantContext::class)->setTenantId($tenantA->id);

        $kasirA = new User();
        $kasirA->tenant_id = $tenantA->id;
        $kasirA->role_id = $roleA->id;
        $kasirA->name = 'Kasir Alpha';
        $kasirA->email = 'kasir.internal@example.test';
        $kasirA->email_normalized = 'kasir.internal@example.test';
        $kasirA->password_hash = bcrypt('Pass123!');
        $kasirA->is_active = true;
        $kasirA->email_verified_at = now();
        $kasirA->save();

        // Mengundang email yang sama di dalam tenant yang sama
        $response = $this->actingAs($ownerA)->post('/users-invitations', [
            'name' => 'Duplikat Kasir',
            'email' => 'kasir.internal@example.test',
            'role_id' => $roleA->id,
            'store_ids' => [$storeA->id],
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_soft_deleted_user_in_other_tenant_can_be_invited_and_activated(): void
    {
        // Tenant A punya user yang sudah di-soft-delete
        [$tenantA, $ownerA, $roleA, $storeA] = $this->createTenantWithStoreAndRoles('Kafe Alpha', 'owner.a@example.test');
        $deletedUser = new User();
        $deletedUser->tenant_id = $tenantA->id;
        $deletedUser->role_id = $roleA->id;
        $deletedUser->name = 'Mantan Karyawan';
        $deletedUser->email = 'mantan@example.test';
        $deletedUser->email_normalized = 'mantan@example.test';
        $deletedUser->password_hash = bcrypt('Pass123!');
        $deletedUser->is_active = true;
        $deletedUser->email_verified_at = now();
        $deletedUser->deleted_at = now(); // Soft deleted
        $deletedUser->save();

        // Tenant B mengundang mantan karyawan tersebut
        [$tenantB, $ownerB, $roleB, $storeB] = $this->createTenantWithStoreAndRoles('Kafe Beta', 'owner.b@example.test');
        app(TenantContext::class)->setTenantId($tenantB->id);

        $service = app(UserInvitationService::class);
        $invitation = $service->issueInvitation($ownerB, [
            'name' => 'Mantan Karyawan',
            'email' => 'mantan@example.test',
            'role_id' => $roleB->id,
            'store_ids' => [$storeB->id],
        ]);

        // Undangan sukses pending
        $this->assertEquals(UserInvitation::STATUS_PENDING, $invitation->status);

        // Aktivasi sukses
        $activatedUser = $service->acceptInvitation($invitation->plaintextToken, [
            'password' => 'NewSecurePassword123!',
        ]);

        $this->assertNotNull($activatedUser);
        $this->assertEquals($tenantB->id, $activatedUser->tenant_id);
    }
}
