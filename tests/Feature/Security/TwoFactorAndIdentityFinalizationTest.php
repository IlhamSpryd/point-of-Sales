<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOAuthIdentity;
use App\Services\Context\TenantContext;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorAndIdentityFinalizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;
    private Store $store;
    private User $owner;
    private Role $ownerRole;
    private TwoFactorAuthService $twoFactorService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->twoFactorService = app(TwoFactorAuthService::class);

        $this->tenant = Tenant::create([
            'name' => 'Kedai Kopi Apeiron',
            'slug' => 'kedai-kopi-apeiron',
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
            'name' => 'Cabang Utama',
            'code' => 'STR-MFA-01',
            'is_initial' => true,
            'is_active' => true,
        ]);

        $this->ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'description' => 'Pemilik Bisnis',
            'permissions' => ['*'],
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->ownerRole->id,
            'name' => 'Budi Santoso',
            'email' => 'owner.mfa@apeiron.test',
            'password_hash' => Hash::make('OwnerSecurePass123!'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Menghasilkan kode TOTP 6 digit valid dari secret untuk offset tertentu.
     */
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

    // ==========================================
    // 1. OAUTH ANTI-LOCKOUT & AUDIT TRAILS
    // ==========================================

    public function test_oauth_unlink_is_blocked_with_audit_log_when_user_has_no_password_and_is_last_credential(): void
    {
        // Set user tanpa password dan hanya 1 tautan OAuth (Apple)
        $this->owner->update([
            'password' => null,
            'password_hash' => null,
        ]);

        UserOAuthIdentity::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'provider' => 'apple',
            'provider_subject' => 'apple-unique-sub-001',
            'provider_email' => 'owner.mfa@apple.test',
            'provider_email_normalized' => 'owner.mfa@apple.test',
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->owner)->delete('/profile/oauth/apple');

        $response->assertSessionHas('error');
        $this->assertTrue(UserOAuthIdentity::where('user_id', $this->owner->id)->where('provider', 'apple')->exists());

        // Verifikasi pencatatan audit trail oauth.unlink_blocked
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'action' => 'oauth.unlink_blocked',
        ]);
    }

    public function test_oauth_unlink_succeeds_with_audit_log_when_user_has_alternative_password(): void
    {
        UserOAuthIdentity::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'provider' => 'google',
            'provider_subject' => 'google-sub-unlink-ok',
            'provider_email' => 'owner.mfa@gmail.com',
            'provider_email_normalized' => 'owner.mfa@gmail.com',
            'provider_email_verified' => true,
            'is_private_relay' => false,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->owner)->delete('/profile/oauth/google');

        $response->assertSessionHas('success');
        $this->assertFalse(UserOAuthIdentity::where('user_id', $this->owner->id)->where('provider', 'google')->exists());

        // Verifikasi pencatatan audit trail oauth.unlinked
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'action' => 'oauth.unlinked',
        ]);
    }

    // ==========================================
    // 2. TWO-FACTOR SETUP & ACTIVATION LIFECYCLE
    // ==========================================

    public function test_owner_can_initiate_two_factor_authentication_setup(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/profile/two-factor/enable');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'secret',
            'qr_uri',
            'recovery_codes',
        ]);

        $this->owner->refresh();
        $this->assertNotEmpty($this->owner->two_factor_secret);
        $this->assertCount(8, $this->owner->two_factor_recovery_codes);
        $this->assertNull($this->owner->two_factor_confirmed_at);
        $this->assertFalse($this->owner->hasTwoFactorEnabled());
    }

    public function test_two_factor_confirmation_fails_with_invalid_code(): void
    {
        $this->actingAs($this->owner)->postJson('/profile/two-factor/enable');
        $this->owner->refresh();

        $response = $this->actingAs($this->owner)->postJson('/profile/two-factor/confirm', [
            'code' => '000000',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Kode autentikasi 6-digit tidak valid atau sudah kedaluwarsa.',
        ]);

        $this->owner->refresh();
        $this->assertNull($this->owner->two_factor_confirmed_at);
        $this->assertFalse($this->owner->hasTwoFactorEnabled());
    }

    public function test_two_factor_confirmation_succeeds_with_valid_totp_code_and_logs_audit(): void
    {
        $this->actingAs($this->owner)->postJson('/profile/two-factor/enable');
        $this->owner->refresh();

        $validCode = $this->generateTotpCode($this->owner->two_factor_secret);

        $response = $this->actingAs($this->owner)->postJson('/profile/two-factor/confirm', [
            'code' => $validCode,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Autentikasi dua faktor berhasil diaktifkan.',
        ]);

        $this->owner->refresh();
        $this->assertNotNull($this->owner->two_factor_confirmed_at);
        $this->assertTrue($this->owner->hasTwoFactorEnabled());

        // Verifikasi audit log two_factor.enabled
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'action' => 'two_factor.enabled',
        ]);
    }

    // ==========================================
    // 3. TOTP DRIFT WINDOW & RECOVERY CODES
    // ==========================================

    public function test_totp_service_accepts_drift_window_plus_minus_one_interval(): void
    {
        $secret = $this->twoFactorService->generateSecretKey();

        $codeCurrent = $this->generateTotpCode($secret, 0);
        $codeMinus1 = $this->generateTotpCode($secret, -1);
        $codePlus1 = $this->generateTotpCode($secret, 1);
        $codeMinus2 = $this->generateTotpCode($secret, -2);
        $codePlus2 = $this->generateTotpCode($secret, 2);

        $this->assertTrue($this->twoFactorService->verifyCode($secret, $codeCurrent));
        $this->assertTrue($this->twoFactorService->verifyCode($secret, $codeMinus1));
        $this->assertTrue($this->twoFactorService->verifyCode($secret, $codePlus1));

        // Di luar window ±1 interval harus ditolak
        $this->assertFalse($this->twoFactorService->verifyCode($secret, $codeMinus2));
        $this->assertFalse($this->twoFactorService->verifyCode($secret, $codePlus2));
    }

    public function test_emergency_recovery_code_is_single_use_burn_after_use(): void
    {
        $secret = $this->twoFactorService->generateSecretKey();
        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes(4);

        $this->owner->two_factor_secret = $secret;
        $this->owner->two_factor_recovery_codes = $recoveryCodes;
        $this->owner->two_factor_confirmed_at = now();
        $this->owner->save();

        $codeToUse = $recoveryCodes[0];

        // Penggunaan pertama harus sukses
        $verified = $this->twoFactorService->verifyRecoveryCode($this->owner, $codeToUse);
        $this->assertTrue($verified);

        $this->owner->refresh();
        $this->assertCount(3, $this->owner->two_factor_recovery_codes);
        $this->assertNotContains($codeToUse, $this->owner->two_factor_recovery_codes);

        // Audit log dicatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'action' => 'two_factor.recovery_code_used',
        ]);

        // Penggunaan kedua dengan kode yang sama HARUS gagal
        $secondAttempt = $this->twoFactorService->verifyRecoveryCode($this->owner, $codeToUse);
        $this->assertFalse($secondAttempt);
    }

    // ==========================================
    // 4. SENSITIVE ACTION MFA CHALLENGE ENFORCEMENT
    // ==========================================

    public function test_unprotected_user_can_access_sensitive_route_without_mfa(): void
    {
        // Owner belum mengaktifkan 2FA -> rute sensitif tidak minta tantangan 2FA
        $this->assertFalse($this->owner->hasTwoFactorEnabled());

        // Mengakses rute yang diproteksi require.two_factor
        $response = $this->actingAs($this->owner)->delete('/profile', [
            'password' => 'OwnerSecurePass123!',
        ]);

        // Karena tidak diproteksi 2FA, aksi diteruskan ke ProfileController::destroy
        $response->assertRedirect('/');
    }

    public function test_protected_user_must_pass_two_factor_challenge_for_sensitive_action(): void
    {
        $secret = $this->twoFactorService->generateSecretKey();
        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();

        $this->owner->two_factor_secret = $secret;
        $this->owner->two_factor_recovery_codes = $recoveryCodes;
        $this->owner->two_factor_confirmed_at = now();
        $this->owner->save();

        $this->assertTrue($this->owner->hasTwoFactorEnabled());

        // 1. Permintaan JSON ke rute sensitif tanpa konfirmasi sesi segar -> 423 Locked
        $jsonResponse = $this->actingAs($this->owner)->deleteJson('/profile');
        $jsonResponse->assertStatus(423);
        $jsonResponse->assertJson([
            'code' => 'TWO_FACTOR_CHALLENGE_REQUIRED',
        ]);

        // 2. Permintaan Web ke rute sensitif dialihkan ke /two-factor-challenge
        $webResponse = $this->actingAs($this->owner)->delete('/profile');
        $webResponse->assertRedirect(route('two-factor.challenge'));

        // 3. Masukkan kode TOTP ke tantangan 2FA
        $validCode = $this->generateTotpCode($secret);
        $challengeResponse = $this->actingAs($this->owner)->post('/two-factor-challenge', [
            'code' => $validCode,
        ]);

        $challengeResponse->assertSessionHas('two_factor_confirmed_at');

        // 4. Setelah terkonfirmasi di sesi, rute sensitif diizinkan
        $allowedResponse = $this->actingAs($this->owner)->delete('/profile', [
            'password' => 'OwnerSecurePass123!',
        ]);
        $allowedResponse->assertRedirect('/');
    }

    public function test_sensitive_action_can_be_unlocked_with_recovery_code(): void
    {
        $secret = $this->twoFactorService->generateSecretKey();
        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();
        $emergencyCode = $recoveryCodes[0];

        $this->owner->two_factor_secret = $secret;
        $this->owner->two_factor_recovery_codes = $recoveryCodes;
        $this->owner->two_factor_confirmed_at = now();
        $this->owner->save();

        // Verifikasi tantangan menggunakan emergency recovery code
        $response = $this->actingAs($this->owner)->postJson('/two-factor-challenge', [
            'code' => $emergencyCode,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $this->owner->refresh();
        $this->assertNotContains($emergencyCode, $this->owner->two_factor_recovery_codes);
    }

    // ==========================================
    // 5. TWO-FACTOR DISABLE
    // ==========================================

    public function test_owner_can_disable_two_factor_with_valid_password(): void
    {
        $secret = $this->twoFactorService->generateSecretKey();
        $this->owner->two_factor_secret = $secret;
        $this->owner->two_factor_recovery_codes = ['REC-01', 'REC-02'];
        $this->owner->two_factor_confirmed_at = now();
        $this->owner->save();

        $this->assertTrue($this->owner->hasTwoFactorEnabled());

        // Password salah -> gagal
        $wrongResponse = $this->actingAs($this->owner)->deleteJson('/profile/two-factor', [
            'password' => 'WrongPassword!',
        ]);
        $wrongResponse->assertStatus(422);

        // Password benar -> sukses
        $okResponse = $this->actingAs($this->owner)->deleteJson('/profile/two-factor', [
            'password' => 'OwnerSecurePass123!',
        ]);
        $okResponse->assertOk();
        $okResponse->assertJson([
            'success' => true,
            'message' => 'Autentikasi dua faktor telah dinonaktifkan.',
        ]);

        $this->owner->refresh();
        $this->assertNull($this->owner->two_factor_secret);
        $this->assertNull($this->owner->two_factor_recovery_codes);
        $this->assertNull($this->owner->two_factor_confirmed_at);
        $this->assertFalse($this->owner->hasTwoFactorEnabled());

        // Audit log dicatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'action' => 'two_factor.disabled',
        ]);
    }
}
