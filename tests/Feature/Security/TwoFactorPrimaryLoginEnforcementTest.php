<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorPrimaryLoginEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;
    private Store $store;
    private User $userWithoutMfa;
    private User $userWithMfa;
    private TwoFactorAuthService $twoFactorService;
    private string $mfaSecret;
    private array $mfaRecoveryCodes;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->twoFactorService = app(TwoFactorAuthService::class);

        $this->tenant = Tenant::create([
            'name' => 'MFA Test Tenant',
            'slug' => 'mfa-test-tenant-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang MFA',
            'code' => 'MFA-01',
            'is_initial' => true,
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
            'is_active' => true,
        ]);

        $this->userWithoutMfa = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'name' => 'User Non-MFA',
            'email' => 'user.nomfa@example.test',
            'email_normalized' => 'user.nomfa@example.test',
            'password' => Hash::make('Password123!'),
            'password_hash' => Hash::make('Password123!'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->mfaSecret = $this->twoFactorService->generateSecretKey();
        $this->mfaRecoveryCodes = $this->twoFactorService->generateRecoveryCodes();

        $this->userWithMfa = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'name' => 'User With-MFA',
            'email' => 'user.withmfa@example.test',
            'email_normalized' => 'user.withmfa@example.test',
            'password' => Hash::make('Password123!'),
            'password_hash' => Hash::make('Password123!'),
            'two_factor_secret' => $this->mfaSecret,
            'two_factor_recovery_codes' => $this->mfaRecoveryCodes,
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function generateTotpCode(string $secret, int $intervalOffset = 0): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(trim($secret));
        $buffer = 0;
        $bitsLeft = 0;
        $binarySecret = '';

        for ($i = 0; $i < strlen($b32); $i++) {
            $char = $b32[$i];
            $val = strpos($alphabet, $char);
            if ($val !== false) {
                $buffer = ($buffer << 5) | $val;
                $bitsLeft += 5;
                if ($bitsLeft >= 8) {
                    $bitsLeft -= 8;
                    $binarySecret .= chr(($buffer >> $bitsLeft) & 0xFF);
                }
            }
        }

        $interval = (int) floor(time() / 30) + $intervalOffset;
        $timeBytes = pack('N*', 0, $interval);
        $hmac = hash_hmac('sha1', $timeBytes, $binarySecret, true);
        $offsetByte = ord($hmac[19]) & 0x0F;
        $binaryCode = (unpack('N', substr($hmac, $offsetByte, 4))[1] & 0x7FFFFFFF) % 1000000;

        return str_pad((string) $binaryCode, 6, '0', STR_PAD_LEFT);
    }

    public function test_user_without_mfa_can_login_directly_to_home(): void
    {
        $response = $this->post('/login', [
            'email' => $this->userWithoutMfa->email,
            'password' => 'Password123!',
        ]);

        $this->assertAuthenticatedAs($this->userWithoutMfa);
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_user_with_mfa_is_redirected_to_challenge_and_not_fully_authenticated(): void
    {
        $response = $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        // Pengguna TIDAK boleh berada dalam status full authentication
        $this->assertGuest();

        // Diarahkan ke rute tantangan 2FA
        $response->assertRedirect(route('two-factor.challenge'));

        // Session memuat state pending MFA
        $response->assertSessionHas('login.mfa_user_id', $this->userWithMfa->id);
        $response->assertSessionHas('login.mfa_started_at');
    }

    public function test_pending_mfa_session_cannot_access_protected_routes(): void
    {
        // 1. Simulasikan primary login
        $loginResponse = $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        $loginResponse->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        // 2. Coba akses rute /home, /profile, /devices menggunakan session pending MFA
        $homeResponse = $this->get('/home');
        $homeResponse->assertRedirect('/login');

        $profileResponse = $this->get('/profile');
        $profileResponse->assertRedirect('/login');

        $devicesResponse = $this->get('/devices');
        $devicesResponse->assertRedirect('/login');
    }

    public function test_invalid_totp_code_fails_challenge_and_leaves_user_unauthenticated(): void
    {
        $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => '000000',
        ]);

        $challengeResponse->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_valid_totp_code_completes_challenge_and_authenticates_user(): void
    {
        $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        $validCode = $this->generateTotpCode($this->mfaSecret);

        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => $validCode,
        ]);

        $this->assertAuthenticatedAs($this->userWithMfa);
        $challengeResponse->assertRedirect(route('home', absolute: false));
        $this->assertNotNull(session('two_factor_confirmed_at'));

        // Audit log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->userWithMfa->id,
            'action' => 'user.login_mfa_completed',
        ]);
    }

    public function test_valid_recovery_code_completes_challenge_and_consumes_the_code(): void
    {
        $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        $codeToUse = $this->mfaRecoveryCodes[0];

        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => $codeToUse,
        ]);

        $this->assertAuthenticatedAs($this->userWithMfa);
        $challengeResponse->assertRedirect(route('home', absolute: false));

        // Kode pemulihan harus hangus (burn after use)
        $this->userWithMfa->refresh();
        $this->assertNotContains($codeToUse, $this->userWithMfa->two_factor_recovery_codes);

        // Logout dan coba login lagi menggunakan recovery code yang sama -> DITOLAK
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        $secondAttempt = $this->post('/two-factor-challenge', [
            'code' => $codeToUse,
        ]);

        $secondAttempt->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_pending_mfa_session_expires_after_timeout(): void
    {
        $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
        ]);

        // Simulasikan waktu berlalu 6 menit (360 detik)
        session(['login.mfa_started_at' => now()->subSeconds(360)->timestamp]);

        $validCode = $this->generateTotpCode($this->mfaSecret);

        $response = $this->post('/two-factor-challenge', [
            'code' => $validCode,
        ]);

        // Harus dialihkan kembali ke login karena sesi expired
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_remember_me_is_applied_only_after_mfa_challenge_succeeds(): void
    {
        $this->post('/login', [
            'email' => $this->userWithMfa->email,
            'password' => 'Password123!',
            'remember' => '1',
        ]);

        // Sebelum challenge selesai: tidak ada remember cookie dan tetap guest
        $this->assertGuest();
        $this->assertTrue(session('login.mfa_remember'));

        $validCode = $this->generateTotpCode($this->mfaSecret);
        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => $validCode,
        ]);

        $this->assertAuthenticatedAs($this->userWithMfa);
    }
}
