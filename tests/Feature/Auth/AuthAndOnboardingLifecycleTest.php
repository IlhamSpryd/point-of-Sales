<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\OwnerOnboardingToken;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthAndOnboardingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantWithOwner(string $email = 'owner@example.test', string $onboardingStatus = Tenant::ONBOARDING_PENDING): array
    {
        $tenant = Tenant::create([
            'name' => 'Kopi Nusantara',
            'slug' => 'kopi-nusantara-'.Str::random(6),
            'status' => 'pending',
            'onboarding_status' => $onboardingStatus,
            'access_status' => Tenant::ACCESS_PENDING,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'trial_14d',
            'country_code' => 'ID',
        ]);

        $ownerRole = new Role();
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->description = 'Tenant Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $user = new User();
        $user->tenant_id = $tenant->id;
        $user->role_id = $ownerRole->id;
        $user->name = 'Budi Pemilik';
        $user->email = $email;
        $user->password_hash = null;
        $user->is_active = true;
        $user->email_verified_at = null;
        $user->failed_login_attempts = 0;
        $user->save();

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi Nusantara Pusat',
            'code' => 'MAIN-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plainToken = Str::random(64);
        $tokenRecord = OwnerOnboardingToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours(48),
        ]);

        return [$tenant, $user, $store, $plainToken, $tokenRecord];
    }

    /**
     * SEC-01: Legacy POST /register route lockdown.
     */
    public function test_legacy_register_routes_are_locked_down(): void
    {
        $getResponse = $this->get('/register');
        $getResponse->assertRedirect('/login');

        $postResponse = $this->post('/register', [
            'name' => 'Attacker',
            'email' => 'attacker@evil.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $postResponse->assertStatus(405);
    }

    /**
     * FN-03 & TM-05: Scanner-safe GET /onboarding/set-password/{token} does not consume token.
     */
    public function test_scanner_safe_get_onboarding_token_does_not_consume_token(): void
    {
        [$tenant, $user, $store, $plainToken, $tokenRecord] = $this->createTenantWithOwner();

        $response = $this->get("/onboarding/set-password/{$plainToken}");

        $response->assertStatus(200);
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $tokenRecord->refresh();
        $this->assertNull($tokenRecord->consumed_at);
        $this->assertFalse($tokenRecord->isConsumed());
    }

    /**
     * FN-03: POST /onboarding/set-password/{token} sets password and consumes token atomically.
     */
    public function test_post_onboarding_token_sets_password_and_consumes_token_atomically(): void
    {
        [$tenant, $user, $store, $plainToken, $tokenRecord] = $this->createTenantWithOwner();

        // 1. Password must meet complexity rules (min 10 chars, mixed case, numbers)
        $weakResponse = $this->post("/onboarding/set-password/{$plainToken}", [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        $weakResponse->assertSessionHasErrors('password');
        $tokenRecord->refresh();
        $this->assertNull($tokenRecord->consumed_at);

        // 2. Strong password setup succeeds
        $successResponse = $this->post("/onboarding/set-password/{$plainToken}", [
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $successResponse->assertRedirect(route('login'));
        $successResponse->assertSessionHas('success');

        $user->refresh();
        $this->assertNotNull($user->password_hash);
        $this->assertTrue(Hash::check('ApeironSecure2026!', $user->password_hash));
        $this->assertNotNull($user->email_verified_at);

        $tokenRecord->refresh();
        $this->assertNotNull($tokenRecord->consumed_at);
        $this->assertTrue($tokenRecord->isConsumed());

        $tenant->refresh();
        $this->assertEquals(Tenant::ONBOARDING_BUSINESS_PROFILE, $tenant->onboarding_status);
    }

    /**
     * SEC-07 & TM-05: Onboarding token cannot be replayed or used after expiry.
     */
    public function test_onboarding_token_cannot_be_replayed_or_used_after_expiry(): void
    {
        [$tenant, $user, $store, $plainToken, $tokenRecord] = $this->createTenantWithOwner();

        // Mark consumed
        $tokenRecord->consume();

        $replayResponse = $this->post("/onboarding/set-password/{$plainToken}", [
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);
        $replayResponse->assertRedirect(route('login'));
        $replayResponse->assertSessionHas('error');

        // Test with expired token on a separate tenant to honor unique(tenant_id, user_id)
        [$tenant2, $user2, $store2, $plainToken2, $tokenRecord2] = $this->createTenantWithOwner('expired@example.test');
        $tokenRecord2->update(['expires_at' => now()->subDay()]);

        $expiredResponse = $this->post("/onboarding/set-password/{$plainToken2}", [
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);
        $expiredResponse->assertRedirect(route('login'));
        $expiredResponse->assertSessionHas('error');
    }

    /**
     * FN-04 & SEC-06: Self-service token resend is rate-limited and enumeration-safe.
     */
    public function test_self_service_onboarding_token_resend_is_rate_limited_and_enumeration_safe(): void
    {
        [$tenant, $user, $store, $plainToken, $tokenRecord] = $this->createTenantWithOwner('unactivated.owner@example.test');

        // Allow cooldown to pass via direct DB update to bypass Eloquent touch
        DB::table('owner_onboarding_tokens')
            ->where('id', $tokenRecord->id)
            ->update(['updated_at' => now()->subMinutes(2)]);
        $tokenRecord->refresh();
        $oldHash = $tokenRecord->token_hash;

        // 1. Resend for non-existent email returns identical generic message (zero enumeration)
        $unknownResponse = $this->post('/onboarding/resend-token', [
            'email' => 'random.nobody@example.test',
        ]);
        $unknownResponse->assertRedirect();
        $unknownResponse->assertSessionHas('success');

        // 2. Resend for valid unactivated owner rotates token and queues outbox record
        $validResponse = $this->post('/onboarding/resend-token', [
            'email' => 'unactivated.owner@example.test',
        ]);
        $validResponse->assertRedirect();
        $validResponse->assertSessionHas('success');

        // Verify token hash was rotated and expires_at renewed
        $tokenRecord->refresh();
        $this->assertNotEquals($oldHash, $tokenRecord->token_hash);
        $this->assertNull($tokenRecord->consumed_at);

        // Verify outbox entry exists
        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenant->id,
            'status' => 'pending',
        ]);

        // 3. Resend rate limiting (max 3 per 15 minutes)
        $this->post('/onboarding/resend-token', ['email' => 'unactivated.owner@example.test']);
        $throttledResponse = $this->post('/onboarding/resend-token', ['email' => 'unactivated.owner@example.test']);
        $throttledResponse->assertStatus(429);
    }

    /**
     * FN-10 & SEC-06: Web login 5-failure lockout for 15 minutes with audit log.
     */
    public function test_web_login_5_failure_lockout_for_15_minutes_with_audit_log(): void
    {
        [$tenant, $user, $store, $plainToken, $tokenRecord] = $this->createTenantWithOwner('lockout.test@example.test');
        $user->update([
            'password_hash' => Hash::make('CorrectPassword123!'),
            'email_verified_at' => now(),
        ]);

        // Attempt 1 to 4: Wrong password
        for ($i = 1; $i <= 4; $i++) {
            $res = $this->post('/login', [
                'email' => 'lockout.test@example.test',
                'password' => 'WrongPassword!',
            ]);
            $res->assertSessionHasErrors('email');
            $user->refresh();
            $this->assertEquals($i, $user->failed_login_attempts);
            $this->assertNull($user->locked_until);
        }

        // Attempt 5: Triggers lockout for 15 minutes
        $res5 = $this->post('/login', [
            'email' => 'lockout.test@example.test',
            'password' => 'WrongPassword!',
        ]);
        $res5->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertEquals(5, $user->failed_login_attempts);
        $this->assertNotNull($user->locked_until);
        $this->assertTrue(Carbon::parse($user->locked_until)->isFuture());

        // Verify activity_logs record
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.auth_locked_out',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);

        // Attempt 6: Even with correct password, user is blocked while locked out
        $res6 = $this->post('/login', [
            'email' => 'lockout.test@example.test',
            'password' => 'CorrectPassword123!',
        ]);
        $res6->assertSessionHasErrors('email');
        $this->assertGuest();

        // Simulate lockout expiry: Fast-forward time
        Carbon::setTestNow(now()->addMinutes(16));

        $resSuccess = $this->post('/login', [
            'email' => 'lockout.test@example.test',
            'password' => 'CorrectPassword123!',
        ]);
        $resSuccess->assertRedirect(route('home', absolute: false));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertEquals(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);

        Carbon::setTestNow(); // Reset time
    }

    /**
     * FN-10: Inactive user and suspended tenant blocked at login.
     */
    public function test_inactive_user_and_suspended_tenant_blocked_at_login(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('suspended.test@example.test');
        $user->update([
            'password_hash' => Hash::make('CorrectPassword123!'),
            'email_verified_at' => now(),
        ]);

        // 1. Inactive user blocked
        $user->update(['is_active' => false]);
        $inactiveRes = $this->post('/login', [
            'email' => 'suspended.test@example.test',
            'password' => 'CorrectPassword123!',
        ]);
        $inactiveRes->assertSessionHasErrors('email');
        $this->assertGuest();

        // 2. Suspended tenant blocked
        $user->update(['is_active' => true]);
        $tenant->update(['access_status' => Tenant::ACCESS_SUSPENDED]);

        $suspendedRes = $this->post('/login', [
            'email' => 'suspended.test@example.test',
            'password' => 'CorrectPassword123!',
        ]);
        $suspendedRes->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * FN-07 & FN-08: Onboarding wizard 2-step flow and save-and-resume.
     */
    public function test_onboarding_wizard_step1_and_step2_flow_and_save_resume(): void
    {
        [$tenant, $user, $store] = $this->createTenantWithOwner('wizard.test@example.test');
        $user->update([
            'password_hash' => Hash::make('CorrectPassword123!'),
            'email_verified_at' => now(),
        ]);

        // Login as Owner
        $this->actingAs($user);

        // Accessing home directs to Step 1
        $homeRes = $this->get(route('home'));
        $homeRes->assertRedirect('/onboarding/business-profile');

        // Complete Step 1
        $step1Res = $this->post('/onboarding/business-profile', [
            'business_name' => 'Kopi Nusantara Baru',
            'business_category' => 'fnb_cafe',
            'country_code' => 'ID',
            'tenant_timezone' => 'Asia/Jakarta',
            'default_currency' => 'IDR',
            'owner_phone' => '+6281122334455',
        ]);
        $step1Res->assertRedirect('/onboarding/initial-branch');

        $tenant->refresh();
        $this->assertEquals(Tenant::ONBOARDING_INITIAL_BRANCH, $tenant->onboarding_status);
        $this->assertEquals('Kopi Nusantara Baru', $tenant->name);

        // Save & Resume: Owner logs out and logs back in
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'wizard.test@example.test',
            'password' => 'CorrectPassword123!',
        ]);
        $this->assertAuthenticatedAs($user);

        // Visiting home automatically resumes at Step 2
        $resumeHome = $this->get(route('home'));
        $resumeHome->assertRedirect('/onboarding/initial-branch');

        // Complete Step 2
        $step2Res = $this->post('/onboarding/initial-branch', [
            'store_name' => 'Kopi Nusantara Flagship',
            'store_code' => 'KOP-01',
            'store_timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '04:00',
            'default_channel' => 'dine_in',
            'store_phone' => '+6281122334455',
        ]);
        $step2Res->assertRedirect(route('dashboard'));

        $tenant->refresh();
        $this->assertEquals(Tenant::ONBOARDING_COMPLETED, $tenant->onboarding_status);
        $this->assertEquals(Tenant::ACCESS_ACTIVE, $tenant->access_status);
        $this->assertNotNull($tenant->onboarding_completed_at);

        // Initial store updated idempotently (store count remains 1)
        $this->assertEquals(1, Store::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
        $store->refresh();
        $this->assertEquals('Kopi Nusantara Flagship', $store->name);
        $this->assertEquals('KOP-01', $store->code);
        $this->assertEquals('04:00', $store->business_date_cutoff);

        // Now home directly redirects to dashboard
        $finalHome = $this->get(route('home'));
        $finalHome->assertRedirect(route('dashboard'));
    }

    /**
     * SEC-09: POS operational routes blocked until onboarding completed.
     */
    public function test_operational_pos_routes_blocked_until_onboarding_completed(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('blocked.test@example.test');
        $user->update([
            'password_hash' => Hash::make('CorrectPassword123!'),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        // Operational routes must redirect to wizard while onboarding is incomplete
        $routes = ['/dashboard', '/transaction', '/reports/sales'];
        foreach ($routes as $route) {
            $res = $this->get($route);
            $res->assertRedirect('/onboarding/business-profile');
        }

        // Complete onboarding
        $tenant->update([
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_completed_at' => now(),
        ]);

        // Operational routes now accessible
        $dashRes = $this->get('/dashboard');
        $dashRes->assertOk();
    }
}
