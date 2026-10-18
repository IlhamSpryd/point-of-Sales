<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOutbox;
use App\Models\EmailVerificationOtp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerRegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        config(['pos.allow_web_registration' => true]);
    }

    public function test_registration_screen_can_be_rendered_when_allowed(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Register your business');
    }

    public function test_owner_can_register_and_receives_6_digit_otp(): void
    {
        $response = $this->post('/register', [
            'name' => 'Budi Pemilik',
            'business_name' => 'Kopi Nusantara Prima',
            'email' => 'budi.nusantara@example.test',
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));

        // Pastikan Tenant dibuat dengan status pending_verification
        $tenant = Tenant::where('name', 'Kopi Nusantara Prima')->first();
        $this->assertNotNull($tenant);
        $this->assertEquals(Tenant::ONBOARDING_PENDING_VERIFICATION, $tenant->onboarding_status);
        $this->assertEquals('pending', $tenant->status);

        // Pastikan User dibuat dengan email_verified_at null
        $user = User::withoutGlobalScopes()->where('email', 'budi.nusantara@example.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals('budi.nusantara@example.test', $user->email_normalized);
        $this->assertNull($user->email_verified_at);
        $this->assertEquals($tenant->id, $user->tenant_id);

        // Pastikan tantangan OTP tersimpan di tabel email_verification_otps dengan hash (bukan plaintext)
        $otp = EmailVerificationOtp::where('user_id', $user->id)->first();
        $this->assertNotNull($otp);
        $this->assertEquals(64, strlen($otp->otp_code_hash));
        $this->assertNull($otp->consumed_at);

        // Pastikan outbox terisi untuk pengiriman email transaksional
        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => EmailOutbox::STATUS_PENDING,
        ]);
    }

    public function test_owner_can_verify_6_digit_otp_and_transitions_to_onboarding_wizard(): void
    {
        $this->post('/register', [
            'name' => 'Siti Owner',
            'business_name' => 'Siti Bakery',
            'email' => 'siti.bakery@example.test',
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $user = User::withoutGlobalScopes()->where('email', 'siti.bakery@example.test')->first();
        $tenant = Tenant::find($user->tenant_id);

        // Terbitkan OTP dengan kode yang diketahui untuk pengujian
        $knownCode = '654321';
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

        $tenant->refresh();
        $this->assertEquals(Tenant::ONBOARDING_BUSINESS_PROFILE, $tenant->onboarding_status);

        $otp = EmailVerificationOtp::where('user_id', $user->id)->first();
        $this->assertNotNull($otp->consumed_at);
    }

    public function test_registration_normalizes_email_unicode_and_case(): void
    {
        $this->post('/register', [
            'name' => 'Case Test',
            'email' => 'Budi.Cafe+Test@DOMAIN.TEST',
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $user = User::withoutGlobalScopes()->where('email_normalized', 'budi.cafe+test@domain.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals('budi.cafe+test@domain.test', $user->email_normalized);
    }

    public function test_duplicate_registration_returns_anti_enumeration_uniform_redirect(): void
    {
        // 1. Registrasi pertama berhasil
        $this->post('/register', [
            'name' => 'Owner Asli',
            'business_name' => 'Kafe Asli',
            'email' => 'kafe.asli@domain.test',
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $initialUserCount = User::withoutGlobalScopes()->count();
        $initialTenantCount = Tenant::count();

        // 2. Registrasi kedua dengan email yang sama (beda huruf kapital)
        $duplicateResponse = $this->post('/register', [
            'name' => 'Attacker Duplicate',
            'business_name' => 'Kafe Duplikat',
            'email' => 'KAFE.ASLI@DOMAIN.TEST',
            'password' => 'DifferentPassword123!',
            'password_confirmation' => 'DifferentPassword123!',
        ]);

        // Respons seragam (anti-enumerasi, tidak membocorkan 422 "email already taken")
        $duplicateResponse->assertSessionDoesntHaveErrors('email');
        $duplicateResponse->assertRedirect(route('login'));

        // Tidak boleh ada penambahan User atau Tenant baru
        $this->assertEquals($initialUserCount, User::withoutGlobalScopes()->count());
        $this->assertEquals($initialTenantCount, Tenant::count());

        // Notifikasi keamanan dikirimkan ke outbox
        $this->assertDatabaseHas('email_outbox', [
            'event_type' => 'duplicate_registration_attempt',
            'recipient_email' => 'kafe.asli@domain.test',
        ]);
    }

    public function test_unverified_owner_is_blocked_from_operational_routes(): void
    {
        $this->post('/register', [
            'name' => 'Unverified Owner',
            'business_name' => 'Unverified Cafe',
            'email' => 'unverified@cafe.test',
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $user = User::withoutGlobalScopes()->where('email', 'unverified@cafe.test')->first();

        // Coba akses dashboard operasional sebelum verifikasi email
        $response = $this->actingAs($user)->get('/dashboard');

        // Harus diblokir oleh EnforceEmailVerification dan diarahkan ke halaman notice
        $response->assertRedirect(route('verification.notice'));
    }
}
