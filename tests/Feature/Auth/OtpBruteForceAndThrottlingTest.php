<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationOtp;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OtpBruteForceAndThrottlingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createUnverifiedOwner(string $email = 'unverified.owner@example.test'): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
            'status' => 'pending',
            'onboarding_status' => Tenant::ONBOARDING_PENDING_VERIFICATION,
            'access_status' => Tenant::ACCESS_PENDING,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
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
        $user->name = 'Owner Test';
        $user->email = $email;
        $user->email_normalized = strtolower($email);
        $user->password_hash = bcrypt('Password123!');
        $user->is_active = true;
        $user->email_verified_at = null;
        $user->save();

        $knownCode = '123456';
        $otp = app(EmailVerificationOtpService::class)->issueOtp($user, 'registration');
        $otp->update([
            'otp_code_hash' => hash_hmac('sha256', $knownCode, config('app.key')),
            'attempts' => 0,
        ]);

        return [$user, $knownCode, $otp];
    }

    public function test_invalid_otp_code_increments_attempts_and_returns_remaining_attempts(): void
    {
        [$user, $knownCode, $otp] = $this->createUnverifiedOwner();

        $response = $this->actingAs($user)->post('/verify-email/otp', [
            'code' => '999999',
        ]);

        $response->assertSessionHasErrors('code');

        $otp->refresh();
        $this->assertEquals(1, $otp->attempts);
        $this->assertNull($otp->locked_at);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_brute_force_5_failed_attempts_locks_otp_challenge(): void
    {
        [$user, $knownCode, $otp] = $this->createUnverifiedOwner();

        // 5 kali salah memasukkan kode
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($user)->post('/verify-email/otp', [
                'code' => '000000',
            ]);
        }

        $otp->refresh();
        $this->assertEquals(5, $otp->attempts);
        $this->assertNotNull($otp->locked_at);

        // Percobaan ke-6 dengan kode BENAR harus tetap DITOLAK karena challenge sudah terkunci
        $lockedResponse = $this->actingAs($user)->post('/verify-email/otp', [
            'code' => $knownCode,
        ]);

        $lockedResponse->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_otp_is_rejected(): void
    {
        [$user, $knownCode, $otp] = $this->createUnverifiedOwner();

        $otp->update(['expires_at' => now()->subMinute()]);

        $response = $this->actingAs($user)->post('/verify-email/otp', [
            'code' => $knownCode,
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_consumed_otp_cannot_be_replayed(): void
    {
        [$user, $knownCode, $otp] = $this->createUnverifiedOwner();

        // Konsumsi pertama sukses
        $success = $this->actingAs($user)->post('/verify-email/otp', [
            'code' => $knownCode,
        ]);
        $success->assertRedirect(route('onboarding.business-profile'));

        // Reset email_verified_at untuk menguji replay attempt
        $user->update(['email_verified_at' => null]);

        // Coba pakai ulang kode yang sama
        $replay = $this->actingAs($user)->post('/verify-email/otp', [
            'code' => $knownCode,
        ]);

        $replay->assertSessionHasErrors('code');
    }

    public function test_resend_within_60_seconds_cooldown_is_throttled_with_429(): void
    {
        [$user, $knownCode, $otp] = $this->createUnverifiedOwner();

        // Resend kedua dalam waktu 5 detik harus ditolak HTTP 429
        Carbon::setTestNow(now()->addSeconds(5));

        $throttled = $this->actingAs($user)->post('/email/verification-notification');

        $throttled->assertStatus(429);
        $this->assertNotNull($throttled->headers->get('Retry-After'));

        Carbon::setTestNow();
    }

    public function test_resend_hourly_limit_exceeded_is_throttled_with_429(): void
    {
        [$user, $knownCode, $otp] = $this->createUnverifiedOwner();

        // Simulasikan 5 permintaan dalam 1 jam terakhir dengan memisahkan waktu di atas cooldown
        for ($i = 1; $i <= 4; $i++) {
            EmailVerificationOtp::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'email' => $user->email,
                'email_normalized' => $user->email_normalized,
                'otp_code_hash' => hash_hmac('sha256', '123456', config('app.key')),
                'action' => 'registration',
                'attempts' => 0,
                'max_attempts' => 5,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now()->subMinutes(10 * $i),
            ]);
        }

        // Mundurkan created_at tantangan terakhir agar lolos cooldown 60 detik
        $otp->update(['created_at' => now()->subMinutes(2)]);

        $throttled = $this->actingAs($user)->post('/email/verification-notification');

        $throttled->assertStatus(429);
    }
}
