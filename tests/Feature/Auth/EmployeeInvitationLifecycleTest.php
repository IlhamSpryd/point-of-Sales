<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\UserInvitationStore;
use App\Services\Context\TenantContext;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantWithOwnerAndRole(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kopi Kafe',
            'slug' => 'kopi-kafe-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store1 = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Pusat',
            'code' => 'MAIN-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $store2 = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Barat',
            'code' => 'WEST-02',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => false,
        ]);

        $ownerRole = new Role();
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->description = 'Tenant Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $kasirRole = new Role();
        $kasirRole->tenant_id = $tenant->id;
        $kasirRole->name = 'Kasir';
        $kasirRole->description = 'POS Cashier';
        $kasirRole->permissions = ['pos.order.create', 'shifts.manage'];
        $kasirRole->is_active = true;
        $kasirRole->save();

        $owner = new User();
        $owner->tenant_id = $tenant->id;
        $owner->role_id = $ownerRole->id;
        $owner->name = 'Owner Kafe';
        $owner->email = 'owner@kafe.test';
        $owner->email_normalized = 'owner@kafe.test';
        $owner->password_hash = bcrypt('Password123!');
        $owner->is_active = true;
        $owner->email_verified_at = now();
        $owner->save();

        return [$tenant, $owner, $kasirRole, [$store1, $store2]];
    }

    public function test_owner_can_issue_employee_invitation_with_sha256_hash_and_email_outbox(): void
    {
        [$tenant, $owner, $kasirRole, $stores] = $this->createTenantWithOwnerAndRole();
        app(TenantContext::class)->setTenantId($tenant->id);

        $response = $this->actingAs($owner)->post('/users-invitations', [
            'name' => 'Budi Kasir',
            'email' => 'budi.kasir@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id, $stores[1]->id],
        ]);

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('success');

        $invitation = UserInvitation::where('email_normalized', 'budi.kasir@example.test')->first();
        $this->assertNotNull($invitation);
        $this->assertEquals($tenant->id, $invitation->tenant_id);
        $this->assertEquals($kasirRole->id, $invitation->role_id);
        $this->assertEquals('pending', $invitation->status);
        $this->assertNotEmpty($invitation->token_hash);
        $this->assertEquals(64, strlen($invitation->token_hash));
        $this->assertTrue($invitation->expires_at->isFuture());

        // Cabang terikat pada user_invitation_stores
        $this->assertEquals(2, UserInvitationStore::where('user_invitation_id', $invitation->id)->count());

        // EmailOutbox menerima antrean pengiriman email
        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenant->id,
            'recipient_email' => 'budi.kasir@example.test',
            'event_type' => 'employee_invitation',
            'status' => 'pending',
        ]);
    }

    public function test_owner_cannot_invite_staff_with_owner_role(): void
    {
        [$tenant, $owner, $kasirRole, $stores] = $this->createTenantWithOwnerAndRole();
        app(TenantContext::class)->setTenantId($tenant->id);

        $response = $this->actingAs($owner)->post('/users-invitations', [
            'name' => 'Hacker Wannabe',
            'email' => 'hacker@example.test',
            'role_id' => $owner->role_id, // Role Owner dilarang
            'store_ids' => [$stores[0]->id],
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('user_invitations', ['email_normalized' => 'hacker@example.test']);
    }

    public function test_owner_can_resend_invitation_which_supersedes_old_and_issues_new_token(): void
    {
        [$tenant, $owner, $kasirRole, $stores] = $this->createTenantWithOwnerAndRole();
        app(TenantContext::class)->setTenantId($tenant->id);

        $service = app(UserInvitationService::class);
        $inv1 = $service->issueInvitation($owner, [
            'name' => 'Siti Barista',
            'email' => 'siti@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id],
        ]);

        $this->assertEquals('pending', $inv1->status);

        // Kirim ulang
        $resendResponse = $this->actingAs($owner)->post("/users-invitations/{$inv1->id}/resend");
        $resendResponse->assertRedirect();
        $resendResponse->assertSessionHas('success');

        $inv1->refresh();
        $this->assertEquals('superseded', $inv1->status);
        $this->assertNotNull($inv1->superseded_at);

        $inv2 = UserInvitation::where('email_normalized', 'siti@example.test')
            ->where('status', 'pending')
            ->first();
        $this->assertNotNull($inv2);
        $this->assertNotEquals($inv1->id, $inv2->id);
        $this->assertNotEquals($inv1->token_hash, $inv2->token_hash);
    }

    public function test_owner_can_revoke_pending_invitation(): void
    {
        [$tenant, $owner, $kasirRole, $stores] = $this->createTenantWithOwnerAndRole();
        app(TenantContext::class)->setTenantId($tenant->id);

        $service = app(UserInvitationService::class);
        $invitation = $service->issueInvitation($owner, [
            'name' => 'Agus Waiter',
            'email' => 'agus@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id],
        ]);

        $response = $this->actingAs($owner)->delete("/users-invitations/{$invitation->id}");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invitation->refresh();
        $this->assertEquals('revoked', $invitation->status);
        $this->assertNotNull($invitation->revoked_at);
    }

    public function test_employee_can_accept_invitation_with_password_and_deferred_pin(): void
    {
        [$tenant, $owner, $kasirRole, $stores] = $this->createTenantWithOwnerAndRole();
        app(TenantContext::class)->setTenantId($tenant->id);

        $service = app(UserInvitationService::class);
        $invitation = $service->issueInvitation($owner, [
            'name' => 'Doni Kasir Baru',
            'email' => 'doni.kasir@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id, $stores[1]->id],
        ]);

        $plaintextToken = $invitation->plaintextToken;

        // 1. Tampilkan halaman penerimaan
        $showResponse = $this->get("/invitations/{$plaintextToken}/accept");
        $showResponse->assertStatus(200);
        $showResponse->assertSee('Doni Kasir Baru');
        $showResponse->assertSee($tenant->name);

        // 2. Kirim password untuk aktivasi
        $acceptResponse = $this->post("/invitations/{$plaintextToken}/accept", [
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $acceptResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Verifikasi User di database
        $createdUser = User::withoutGlobalScopes()->where('email_normalized', 'doni.kasir@example.test')->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals($tenant->id, $createdUser->tenant_id);
        $this->assertEquals($kasirRole->id, $createdUser->role_id);
        $this->assertTrue($createdUser->is_active);
        $this->assertNotNull($createdUser->email_verified_at);
        $this->assertNotNull($createdUser->employee_id);
        $this->assertStringContainsString((string) $tenant->id, $createdUser->employee_id);

        // Deferred Cashier PIN: pin_hash harus NULL saat aktivasi
        $this->assertNull($createdUser->pin_hash);

        // User stores terpasang
        $this->assertEquals(2, $createdUser->stores()->count());

        // Status undangan terupdate
        $invitation->refresh();
        $this->assertEquals('accepted', $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_expired_revoked_superseded_and_replayed_tokens_are_strictly_rejected(): void
    {
        [$tenant, $owner, $kasirRole, $stores] = $this->createTenantWithOwnerAndRole();
        app(TenantContext::class)->setTenantId($tenant->id);
        $service = app(UserInvitationService::class);

        // 1. Token kedaluwarsa (>72 jam)
        $invExpired = $service->issueInvitation($owner, [
            'name' => 'Expired User',
            'email' => 'expired@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id],
        ]);
        $invExpired->update(['expires_at' => now()->subMinute()]);
        $resExpired = $this->post("/invitations/{$invExpired->plaintextToken}/accept", [
            'password' => 'Pass12345!',
            'password_confirmation' => 'Pass12345!',
        ]);
        $resExpired->assertStatus(410);

        // 2. Token dicabut (revoked)
        $invRevoked = $service->issueInvitation($owner, [
            'name' => 'Revoked User',
            'email' => 'revoked@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id],
        ]);
        $service->revokeInvitation($owner, $invRevoked->id);
        $resRevoked = $this->post("/invitations/{$invRevoked->plaintextToken}/accept", [
            'password' => 'Pass12345!',
            'password_confirmation' => 'Pass12345!',
        ]);
        $resRevoked->assertStatus(410);

        // 3. Token digantikan (superseded)
        $invSuperseded = $service->issueInvitation($owner, [
            'name' => 'Superseded User',
            'email' => 'superseded@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id],
        ]);
        $service->resendInvitation($owner, $invSuperseded->id);
        $resSuperseded = $this->post("/invitations/{$invSuperseded->plaintextToken}/accept", [
            'password' => 'Pass12345!',
            'password_confirmation' => 'Pass12345!',
        ]);
        $resSuperseded->assertStatus(410);

        // 4. Token sudah pernah digunakan (replayed)
        $invReplayed = $service->issueInvitation($owner, [
            'name' => 'Replayed User',
            'email' => 'replayed@example.test',
            'role_id' => $kasirRole->id,
            'store_ids' => [$stores[0]->id],
        ]);
        $service->acceptInvitation($invReplayed->plaintextToken, ['password' => 'Pass12345!']);
        $resReplayed = $this->post("/invitations/{$invReplayed->plaintextToken}/accept", [
            'password' => 'Pass12345!',
            'password_confirmation' => 'Pass12345!',
        ]);
        $resReplayed->assertStatus(410);
    }
}
