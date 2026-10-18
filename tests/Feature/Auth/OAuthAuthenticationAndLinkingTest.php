<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOAuthIdentity;
use App\Services\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class OAuthAuthenticationAndLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        OAuthService::clearFakes();
    }

    private function createTenantWithOwner(string $email = 'owner@example.test'): array
    {
        $tenant = Tenant::create([
            'name' => 'Kopi Kafe',
            'slug' => 'kopi-kafe-'.uniqid(),
            'status' => 'pending',
            'onboarding_status' => Tenant::ONBOARDING_BUSINESS_PROFILE,
            'access_status' => Tenant::ACCESS_PENDING,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi Kafe Pusat',
            'code' => 'MAIN-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $ownerRole = new Role;
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->description = 'Tenant Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $user = new User;
        $user->tenant_id = $tenant->id;
        $user->role_id = $ownerRole->id;
        $user->name = 'Owner Kafe';
        $user->email = $email;
        $user->email_normalized = strtolower($email);
        $user->password_hash = bcrypt('SecurePassword123!');
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        return [$tenant, $user];
    }

    public function test_google_oauth_redirect_generates_state_nonce_and_pkce(): void
    {
        $response = $this->get('/auth/oauth/google/redirect?intent=login');

        $response->assertStatus(302);
        $redirectUrl = (string) $response->headers->get('Location');

        $this->assertStringContainsString('accounts.google.com', $redirectUrl);
        $this->assertStringContainsString('state=', $redirectUrl);
        $this->assertStringContainsString('nonce=', $redirectUrl);
        $this->assertStringContainsString('code_challenge=', $redirectUrl);

        // Ekstraksi parameter state dari redirect URL
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $queryParams);
        $state = $queryParams['state'] ?? '';

        $this->assertNotEmpty($state);
        $this->assertTrue(Cache::has("oauth_state:{$state}"));
    }

    public function test_google_oauth_valid_login_authenticates_user_and_consumes_state(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();

        $sub = 'google-sub-'.Str::random(16);
        UserOAuthIdentity::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => $sub,
            'provider_email' => $user->email,
            'provider_email_normalized' => $user->email_normalized,
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
            'last_used_at' => null,
        ]);

        $state = 'test_state_'.Str::random(16);
        $nonce = 'test_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'login',
            'provider' => 'google',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('google', [
            'sub' => $sub,
            'email' => $user->email,
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        $response = $this->get("/auth/oauth/google/callback?state={$state}&code=test_code");

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('onboarding.business-profile'));

        // Pastikan state ditarik secara atomik dan tidak lagi ada di Cache (anti-replay)
        $this->assertFalse(Cache::has("oauth_state:{$state}"));
    }

    public function test_replaying_consumed_state_is_rejected(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();
        $sub = 'google-sub-'.Str::random(16);
        UserOAuthIdentity::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => $sub,
            'provider_email' => $user->email,
            'provider_email_normalized' => $user->email_normalized,
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
            'last_used_at' => null,
        ]);

        $state = 'replay_state_'.Str::random(16);
        $nonce = 'replay_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'login',
            'provider' => 'google',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('google', [
            'sub' => $sub,
            'email' => $user->email,
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        // Request pertama: Sukses
        $res1 = $this->get("/auth/oauth/google/callback?state={$state}&code=test_code");
        $res1->assertRedirect();
        $this->assertAuthenticated();

        Auth::logout();

        // Request kedua dengan state yang sama: DITOLAK
        $res2 = $this->get("/auth/oauth/google/callback?state={$state}&code=test_code");
        $res2->assertRedirect(route('login'));
        $res2->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_apple_form_post_callback_without_csrf_failure(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('apple.user@example.test');
        $sub = 'apple-sub-'.Str::random(16);

        UserOAuthIdentity::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'provider' => 'apple',
            'provider_subject' => $sub,
            'provider_email' => $user->email,
            'provider_email_normalized' => $user->email_normalized,
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
            'last_used_at' => null,
        ]);

        $state = 'apple_state_'.Str::random(16);
        $nonce = 'apple_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'login',
            'provider' => 'apple',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('apple', [
            'sub' => $sub,
            'email' => $user->email,
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        // Apple POST form_post callback tanpa CSRF token (harus lolos dari CSRF middleware)
        $response = $this->post('/auth/oauth/apple/callback', [
            'state' => $state,
            'code' => 'apple_code',
            'id_token' => 'dummy_id_token',
        ]);

        $response->assertStatus(302);
        $this->assertAuthenticatedAs($user);
    }

    public function test_apple_capture_name_on_first_registration_and_subsequent_login_without_user_payload(): void
    {
        $sub = 'apple-unique-sub-'.Str::random(16);
        $state = 'apple_reg_state_'.Str::random(16);
        $nonce = 'apple_reg_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'register_owner',
            'provider' => 'apple',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('apple', [
            'sub' => $sub,
            'email' => 'steve.jobs@apple.test',
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        // 1. Registrasi pertama: Apple mengirimkan payload 'user' berformat JSON
        $registerRes = $this->post('/auth/oauth/apple/callback', [
            'state' => $state,
            'code' => 'code_1',
            'id_token' => 'id_token_1',
            'user' => json_encode(['name' => ['firstName' => 'Steve', 'lastName' => 'Jobs']]),
        ]);

        $registerRes->assertRedirect(route('onboarding.business-profile'));

        $registeredUser = User::withoutGlobalScopes()->where('email', 'steve.jobs@apple.test')->first();
        $this->assertNotNull($registeredUser);
        $this->assertEquals('Steve Jobs', $registeredUser->name);
        $this->assertNull($registeredUser->password_hash);
        $this->assertNotNull($registeredUser->email_verified_at);

        Auth::logout();

        // 2. Login berikutnya: Apple TIDAK menyertakan payload 'user'
        $loginState = 'apple_login_state_'.Str::random(16);
        $loginNonce = 'apple_login_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$loginState}", [
            'state' => $loginState,
            'nonce' => $loginNonce,
            'code_verifier' => Str::random(64),
            'intent' => 'login',
            'provider' => 'apple',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('apple', [
            'sub' => $sub,
            'email' => 'steve.jobs@apple.test',
            'email_verified' => true,
            'nonce' => $loginNonce,
        ]);

        $loginRes = $this->post('/auth/oauth/apple/callback', [
            'state' => $loginState,
            'code' => 'code_2',
            'id_token' => 'id_token_2',
        ]);

        $this->assertAuthenticatedAs($registeredUser);
        $loginRes->assertRedirect(route('onboarding.business-profile'));
    }

    public function test_apple_private_relay_email_sets_flag_and_authenticates(): void
    {
        $sub = 'apple-relay-sub-'.Str::random(16);
        $state = 'relay_state_'.Str::random(16);
        $nonce = 'relay_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'register_owner',
            'provider' => 'apple',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('apple', [
            'sub' => $sub,
            'email' => 'randomuser123@privaterelay.appleid.com',
            'is_private_email' => true,
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        $res = $this->post('/auth/oauth/apple/callback', [
            'state' => $state,
            'code' => 'code_relay',
            'id_token' => 'id_token_relay',
        ]);

        $res->assertRedirect(route('onboarding.business-profile'));

        $identity = UserOAuthIdentity::where('provider', 'apple')
            ->where('provider_subject', $sub)
            ->first();

        $this->assertNotNull($identity);
        $this->assertTrue($identity->is_private_relay);
    }

    public function test_unregistered_account_on_login_is_rejected_zero_auto_provisioning(): void
    {
        $state = 'unknown_state_'.Str::random(16);
        $nonce = 'unknown_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'login',
            'provider' => 'google',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('google', [
            'sub' => 'unknown-sub-'.Str::random(16),
            'email' => 'new.stranger@example.test',
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        $tenantCountBefore = Tenant::count();
        $userCountBefore = User::withoutGlobalScopes()->count();

        $response = $this->get("/auth/oauth/google/callback?state={$state}&code=code_unknown");

        // DILARANG AUTO-PROVISIONING: Harus ditolak dan diarahkan ke login
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();

        // Tidak ada tenant atau user baru yang diciptakan
        $this->assertEquals($tenantCountBefore, Tenant::count());
        $this->assertEquals($userCountBefore, User::withoutGlobalScopes()->count());
    }

    public function test_existing_email_without_prior_link_on_login_is_rejected_zero_auto_link(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('existing.owner@domain.test');

        $state = 'autolink_state_'.Str::random(16);
        $nonce = 'autolink_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'login',
            'provider' => 'google',
            'initiator_user_id' => null,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('google', [
            'sub' => 'google-unlinked-sub-999',
            'email' => 'existing.owner@domain.test',
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        $response = $this->get("/auth/oauth/google/callback?state={$state}&code=code_autolink");

        // DILARANG AUTO-LINK: Harus ditolak dan meminta user login kredensial terlebih dahulu
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();

        // Tidak boleh ada kaitan yang dibuat otomatis
        $this->assertFalse(UserOAuthIdentity::where('provider_subject', 'google-unlinked-sub-999')->exists());

        // Dicatat di ActivityLog untuk audit keamanan
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'oauth_auto_link_blocked',
            'user_id' => $user->id,
        ]);
    }

    public function test_authenticated_user_can_link_and_unlink_oauth_provider_with_anti_lockout(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();

        // 1. Tautkan akun Google dari profil
        $linkSub = 'google-link-sub-'.Str::random(16);
        $state = 'link_state_'.Str::random(16);
        $nonce = 'link_nonce_'.Str::random(16);

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => Str::random(64),
            'intent' => 'link_account',
            'provider' => 'google',
            'initiator_user_id' => $user->id,
        ], now()->addMinutes(10));

        OAuthService::fakeTokenPayload('google', [
            'sub' => $linkSub,
            'email' => 'linked.google@example.test',
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        $linkResponse = $this->actingAs($user)->get("/auth/oauth/google/callback?state={$state}&code=code_link");
        $linkResponse->assertRedirect(route('profile.edit'));
        $linkResponse->assertSessionHas('success');

        $this->assertDatabaseHas('user_oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => $linkSub,
        ]);

        // Notifikasi keamanan penautan terkirim via EmailOutbox
        $this->assertDatabaseHas('email_outbox', [
            'event_type' => 'oauth_account_linked',
            'user_id' => $user->id,
        ]);

        // 2. Lepas tautan Google (diizinkan karena user punya password_hash)
        $unlinkResponse = $this->actingAs($user)->delete('/profile/oauth/google');
        $unlinkResponse->assertSessionHas('success');
        $this->assertFalse(UserOAuthIdentity::where('user_id', $user->id)->where('provider', 'google')->exists());

        // 3. Uji Anti-Lockout: user tanpa password dan hanya punya 1 provider OAuth dilarang unlink
        $user->update(['password_hash' => null]);
        UserOAuthIdentity::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'provider' => 'apple',
            'provider_subject' => 'only-apple-login',
            'provider_email' => 'user@apple.test',
            'provider_email_normalized' => 'user@apple.test',
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
        ]);

        $blockedUnlink = $this->actingAs($user)->delete('/profile/oauth/apple');
        $blockedUnlink->assertSessionHas('error');

        // Pastikan akun Apple TIDAK terhapus
        $this->assertTrue(UserOAuthIdentity::where('user_id', $user->id)->where('provider', 'apple')->exists());
    }

    public function test_profile_page_renders_oauth_linking_controls_and_status(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();

        // 1. Awalnya belum tertaut
        $res1 = $this->actingAs($user)->get(route('profile.edit'));
        $res1->assertOk();
        $res1->assertSee('Akun Tertaut (Google &amp; Apple SSO)', false);
        $res1->assertSee('Tautkan Akun Google');
        $res1->assertSee('Tautkan Akun Apple');

        // 2. Tautkan Google
        UserOAuthIdentity::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'google-sub-12345',
            'provider_email' => 'user.google@gmail.com',
            'provider_email_normalized' => 'user.google@gmail.com',
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
        ]);

        // 3. Tampilan profil kini menampilkan status tertaut dan tombol lepas tautan
        $res2 = $this->actingAs($user)->get(route('profile.edit'));
        $res2->assertOk();
        $res2->assertSee('user.google@gmail.com');
        $res2->assertSee('Tertaut');
        $res2->assertSee('btn-unlink-google', false);
    }
}
