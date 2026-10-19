<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationOtp;
use App\Models\OwnerOnboardingToken;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComprehensiveAuthAndRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private string $provisioningSecret = 'test-auth-provisioning-secret-key-2026';

    private string $provisioningPath = '/api/v1/provisioning/registrations';

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        config(['services.provisioning.secret_current' => $this->provisioningSecret]);
        config(['services.provisioning.secret_previous' => null]);
        config(['pos.allow_web_registration' => true]);
    }

    private function createTenantWithOwner(string $email, bool $onboarded = false): array
    {
        $tenant = Tenant::create([
            'name' => 'Kopi Sentosa',
            'slug' => 'kopi-sentosa-'.Str::random(6),
            'status' => 'pending',
            'onboarding_status' => $onboarded ? Tenant::ONBOARDING_COMPLETED : Tenant::ONBOARDING_PENDING,
            'access_status' => $onboarded ? Tenant::ACCESS_ACTIVE : Tenant::ACCESS_PENDING,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        app(TenantContext::class)->setTenantId($tenant->id);

        $role = new Role;
        $role->tenant_id = $tenant->id;
        $role->name = 'Owner';
        $role->description = 'Tenant Owner';
        $role->permissions = ['*'];
        $role->is_active = true;
        $role->save();

        $user = new User;
        $user->tenant_id = $tenant->id;
        $user->role_id = $role->id;
        $user->name = 'Sentosa Owner';
        $user->email = $email;
        $user->phone_number = '+6285718965973';
        $user->password_hash = Hash::make('StrongPassword2026!');
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->failed_login_attempts = 0;
        $user->save();

        Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi Sentosa Pusat',
            'code' => 'MAIN-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        return [$tenant, $user];
    }

    private function signedProvisioningHeaders(array $payload, string $idempotencyKey): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $canonical = implode("\n", ['POST', $this->provisioningPath, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $this->provisioningSecret);

        return [
            'Content-Type' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Idempotency-Key' => $idempotencyKey,
        ];
    }

    // -------------------------------------------------------------------------
    // 1. LOGIN SUITE
    // -------------------------------------------------------------------------

    public function test_login_page_renders_with_password_eye_icon_and_no_text_toggles(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        // Eye icon SVG is present
        $response->assertSee('<circle cx="12" cy="12" r="3"/>', false);
        $response->assertSee('Show password');
        $response->assertSee('Hide password');

        // No plain text Show / Hide buttons
        $response->assertDontSee('>Show</button>', false);
        $response->assertDontSee('>Hide</button>', false);
        $response->assertDontSee('>Show<', false);
    }

    public function test_user_with_pending_onboarding_logs_in_and_redirects_to_onboarding_step_1(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('pending.owner@example.test', false);

        $response = $this->post('/login', [
            'email' => 'pending.owner@example.test',
            'password' => 'StrongPassword2026!',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('home'));

        // Following home redirection lands on onboarding step 1
        $homeRes = $this->get(route('home'));
        $homeRes->assertRedirect(route('onboarding.business-profile'));
    }

    public function test_user_with_completed_onboarding_logs_in_and_redirects_to_dashboard(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('completed.owner@example.test', true);

        $response = $this->post('/login', [
            'email' => 'completed.owner@example.test',
            'password' => 'StrongPassword2026!',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('home'));

        $homeRes = $this->get(route('home'));
        $homeRes->assertRedirect(route('dashboard'));
    }

    public function test_login_fails_with_invalid_password_and_user_remains_guest(): void
    {
        $this->createTenantWithOwner('invalid.test@example.test');

        $response = $this->post('/login', [
            'email' => 'invalid.test@example.test',
            'password' => 'WrongPassword123!',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('inactive.test@example.test');
        $user->update(['is_active' => false]);

        $response = $this->post('/login', [
            'email' => 'inactive.test@example.test',
            'password' => 'StrongPassword2026!',
        ]);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_log_out(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('logout.test@example.test');

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    // -------------------------------------------------------------------------
    // 2. DIRECT REGISTRATION SUITE (WITH OTP)
    // -------------------------------------------------------------------------

    public function test_register_page_renders_with_eye_icons(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('Register your business');
        $response->assertSee('<circle cx="12" cy="12" r="3"/>', false);
    }

    public function test_direct_registration_rejects_unmatched_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Budi Pemilik',
            'business_name' => 'Kopi Mismatch',
            'email' => 'mismatch@example.test',
            'password' => 'PasswordA123!',
            'password_confirmation' => 'PasswordB999!',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['password']);
    }

    public function test_direct_registration_creates_pending_tenant_and_issues_otp(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ilham Sepriyadi',
            'business_name' => 'Percobaan02',
            'email' => 'ilham.sepriyadi@example.test',
            'password' => 'ApeironMaster2026!',
            'password_confirmation' => 'ApeironMaster2026!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));

        $tenant = Tenant::where('name', 'Percobaan02')->first();
        $this->assertNotNull($tenant);
        $this->assertEquals(Tenant::ONBOARDING_PENDING_VERIFICATION, $tenant->onboarding_status);

        $user = User::withoutGlobalScopes()->where('email', 'ilham.sepriyadi@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        $otp = EmailVerificationOtp::where('user_id', $user->id)->first();
        $this->assertNotNull($otp);
        $this->assertEquals(64, strlen($otp->otp_code_hash));
    }

    public function test_valid_otp_verification_authenticates_and_redirects_to_onboarding_step_1(): void
    {
        $this->post('/register', [
            'name' => 'Verif Owner',
            'business_name' => 'Verif Coffee',
            'email' => 'verif.owner@example.test',
            'password' => 'ApeironMaster2026!',
            'password_confirmation' => 'ApeironMaster2026!',
        ]);

        $user = User::withoutGlobalScopes()->where('email', 'verif.owner@example.test')->first();

        // Seed predictable OTP code
        $knownCode = '123456';
        $codeHash = hash_hmac('sha256', $knownCode, config('app.key'));
        EmailVerificationOtp::where('user_id', $user->id)->update([
            'otp_code_hash' => $codeHash,
            'attempts' => 0,
        ]);

        $response = $this->actingAs($user)->post('/verify-email/otp', [
            'code' => $knownCode,
        ]);

        $response->assertRedirect(route('onboarding.business-profile'));

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    // -------------------------------------------------------------------------
    // 3. S2S PROVISIONING & MAGIC LINK ACTIVATION SUITE
    // -------------------------------------------------------------------------

    public function test_s2s_provisioning_registers_business_and_issues_onboarding_token(): string
    {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'tenant_name' => 'Kopi Roastery Modern',
            'owner_name' => 'Rian Hidayat',
            'owner_email' => 'rian.hidayat@example.test',
            'owner_phone' => '+6281234567890',
        ];

        $headers = $this->signedProvisioningHeaders($payload, $idempotencyKey);
        $response = $this->postJson($this->provisioningPath, $payload, $headers);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);

        $tenant = Tenant::where('name', 'Kopi Roastery Modern')->first();
        $this->assertNotNull($tenant);

        $owner = User::withoutGlobalScopes()->where('email', 'rian.hidayat@example.test')->first();
        $this->assertNotNull($owner);
        $this->assertEquals('+6281234567890', $owner->phone_number);

        $tokenRecord = OwnerOnboardingToken::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertNull($tokenRecord->consumed_at);

        $data = $response->json();
        $this->assertNotEmpty($data['onboarding']['onboarding_token']);

        return $data['onboarding']['onboarding_token'];
    }

    public function test_owner_activation_page_renders_with_eye_icons(): void
    {
        $token = $this->test_s2s_provisioning_registers_business_and_issues_onboarding_token();

        $response = $this->get('/onboarding/set-password/'.$token);

        $response->assertOk();
        $response->assertSee('Set your password');
        $response->assertSee('<circle cx="12" cy="12" r="3"/>', false);
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_owner_sets_master_password_and_redirects_to_onboarding_step_1(): void
    {
        config(['pos.onboarding_wizard_redirect' => true]);
        $token = $this->test_s2s_provisioning_registers_business_and_issues_onboarding_token();

        $response = $this->post('/onboarding/set-password/'.$token, [
            'password' => 'ApeironMasterKey2026!',
            'password_confirmation' => 'ApeironMasterKey2026!',
        ]);

        $response->assertRedirect(route('onboarding.business-profile'));

        $tokenRecord = OwnerOnboardingToken::where('token_hash', hash('sha256', $token))->first();
        $this->assertNotNull($tokenRecord->consumed_at);

        $owner = User::withoutGlobalScopes()->where('email', 'rian.hidayat@example.test')->first();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('ApeironMasterKey2026!', $owner->password_hash));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_owner_sets_master_password_redirects_to_login_when_auto_wizard_disabled(): void
    {
        config(['pos.onboarding_wizard_redirect' => false]);
        $token = $this->test_s2s_provisioning_registers_business_and_issues_onboarding_token();

        $response = $this->post('/onboarding/set-password/'.$token, [
            'password' => 'ApeironMasterKey2026!',
            'password_confirmation' => 'ApeironMasterKey2026!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success');
    }

    public function test_expired_or_invalid_activation_token_redirects_to_login_with_error(): void
    {
        $response = $this->get('/onboarding/set-password/invalid-or-fake-token-value-999');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
    }

    // -------------------------------------------------------------------------
    // 4. ONBOARDING STEP 1 & 2 ZERO-SCROLL WORKSPACE COMPLETION
    // -------------------------------------------------------------------------

    public function test_end_to_end_onboarding_completes_and_unlocks_dashboard(): void
    {
        [$tenant, $owner] = $this->createTenantWithOwner('e2e.onboarding@example.test', false);

        $this->actingAs($owner);

        // Step 1: Business Profile Viewport-fitted page
        $step1Get = $this->get(route('onboarding.business-profile'));
        $step1Get->assertOk();
        $step1Get->assertSee('Registered Account Details');
        $step1Get->assertSee('Kopi Sentosa');
        $step1Get->assertSee('Sentosa Owner');
        $step1Get->assertSee('+6285718965973');

        // Submit Step 1 without typing redundant brand / phone
        $step1Post = $this->post(route('onboarding.business-profile.store'), [
            'business_category' => 'fnb_cafe',
            'tenant_timezone' => 'Asia/Jakarta',
        ]);
        $step1Post->assertRedirect(route('onboarding.initial-branch'));

        $tenant->refresh();
        $this->assertEquals(Tenant::ONBOARDING_INITIAL_BRANCH, $tenant->onboarding_status);

        // Step 2: Initial Branch
        $step2Get = $this->get(route('onboarding.initial-branch'));
        $step2Get->assertOk();
        $step2Get->assertSee('Configure your first branch');

        // Submit Step 2
        $step2Post = $this->post(route('onboarding.initial-branch.store'), [
            'store_name' => 'Kopi Sentosa Flagship',
            'store_code' => 'SEN-01',
            'store_timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
        ]);
        $step2Post->assertRedirect(route('dashboard'));

        $tenant->refresh();
        $this->assertEquals(Tenant::ONBOARDING_COMPLETED, $tenant->onboarding_status);
        $this->assertEquals(Tenant::ACCESS_ACTIVE, $tenant->access_status);

        // Accessing dashboard is now fully authorized
        $dashRes = $this->get(route('dashboard'));
        $dashRes->assertOk();
    }
}
