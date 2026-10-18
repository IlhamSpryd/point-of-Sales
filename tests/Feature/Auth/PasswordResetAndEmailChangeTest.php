<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOutbox;
use App\Models\EmailVerificationOtp;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Context\TenantContext;
use App\Services\EmailVerificationOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetAndEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantWithOwner(string $email = 'owner.reset@example.test'): array
    {
        $tenant = Tenant::create([
            'name' => 'Kopi Kafe Reset',
            'slug' => 'kopi-reset-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Reset',
            'code' => 'RST-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $role = new Role();
        $role->tenant_id = $tenant->id;
        $role->name = 'Owner';
        $role->permissions = ['*'];
        $role->is_active = true;
        $role->save();

        $user = new User();
        $user->tenant_id = $tenant->id;
        $user->role_id = $role->id;
        $user->name = 'Owner Reset';
        $user->email = $email;
        $user->email_normalized = strtolower($email);
        $user->password_hash = bcrypt('OldPassword123!');
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        return [$tenant, $user, $store];
    }

    public function test_forgot_password_request_routes_to_email_outbox_with_anti_enumeration(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('user.exists@example.test');

        // 1. Email yang terdaftar: menerima respons sukses seragam & email outbox tersimpan
        $response1 = $this->post('/forgot-password', [
            'email' => 'user.exists@example.test',
        ]);

        $response1->assertRedirect();
        $response1->assertSessionHas('status');

        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenant->id,
            'recipient_email' => 'user.exists@example.test',
            'event_type' => 'password_reset',
        ]);

        // 2. Email yang TIDAK terdaftar: menerima respons sukses seragam yang identik (Anti-Enumerasi [REQ-SEC-23])
        $response2 = $this->post('/forgot-password', [
            'email' => 'stranger.notfound@example.test',
        ]);

        $response2->assertRedirect();
        $response2->assertSessionHas('status');

        // Tidak ada baris email_outbox baru untuk email asing
        $this->assertDatabaseMissing('email_outbox', [
            'recipient_email' => 'stranger.notfound@example.test',
        ]);
    }

    public function test_password_reset_revokes_sessions_and_cannot_be_replayed(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('reset.target@example.test');

        // Buat mock sesi di tabel sessions
        DB::table('sessions')->insert([
            'id' => 'session_device_1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'dummy_payload',
            'last_activity' => time(),
        ]);

        $this->assertDatabaseHas('sessions', ['id' => 'session_device_1']);

        // Dapatkan token reset resmi
        $token = Password::broker()->createToken($user);

        // Reset password pertama: Sukses
        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'reset.target@example.test',
            'password' => 'ApeironSecure2026!',
            'password_confirmation' => 'ApeironSecure2026!',
        ]);

        $response->assertRedirect(route('login'));
        $user->refresh();

        $this->assertTrue(Hash::check('ApeironSecure2026!', $user->password_hash));
        $this->assertNotNull($user->password_changed_at);

        // Seluruh sesi perangkat sebelumnya di tabel sessions telah dicabut
        $this->assertDatabaseMissing('sessions', ['id' => 'session_device_1']);

        // Upaya replay menggunakan token yang sama: DITOLAK
        $replayResponse = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'reset.target@example.test',
            'password' => 'ApeironSecure2027!',
            'password_confirmation' => 'ApeironSecure2027!',
        ]);

        $replayResponse->assertSessionHasErrors('email');
    }

    public function test_verify_before_swap_email_change_lifecycle(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner('initial.email@example.test');
        app(TenantContext::class)->setTenantId($tenant->id);

        // 1. Coba ubah email tanpa kata sandi saat ini: DITOLAK
        $unauthReq = $this->actingAs($user)->post('/profile/email/request', [
            'current_password' => 'WrongPassword!',
            'new_email' => 'brand.new@example.test',
        ]);
        $unauthReq->assertSessionHasErrors('current_password');

        // 2. Ajukan dengan password benar: Email belum berubah, pending_email terisi
        $validReq = $this->actingAs($user)->post('/profile/email/request', [
            'current_password' => 'OldPassword123!',
            'new_email' => 'brand.new@example.test',
        ]);
        $validReq->assertSessionHas('status', 'pending-email-otp-sent');

        $user->refresh();
        $this->assertEquals('initial.email@example.test', $user->email); // BELUM BERUBAH
        $this->assertNotNull($user->email_verified_at); // MASIH TERVERIFIKASI
        $this->assertEquals('brand.new@example.test', $user->pending_email);

        // Peringatan keamanan terkirim ke email lama via EmailOutbox
        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenant->id,
            'recipient_email' => 'initial.email@example.test',
            'event_type' => 'email_change_requested',
        ]);

        // 3. Masukkan kode OTP salah: Ditolak, email tetap belum berubah
        $wrongOtp = $this->actingAs($user)->post('/profile/email/verify', [
            'otp_code' => '999999',
        ]);
        $wrongOtp->assertSessionHas('error');

        $user->refresh();
        $this->assertEquals('initial.email@example.test', $user->email);

        // Ambil kode OTP sebenarnya dari EmailVerificationOtp
        $otpRow = EmailVerificationOtp::where('user_id', $user->id)
            ->where('action', 'email_change')
            ->first();
        $this->assertNotNull($otpRow);

        // Buat mock session device 2
        DB::table('sessions')->insert([
            'id' => 'device_session_to_be_purged',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mobile',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        // Verifikasi dengan kode yang valid
        $otpService = app(EmailVerificationOtpService::class);
        $reflection = new \ReflectionClass($otpService);
        // Terbitkan OTP baru dengan kode yang kita ketahui untuk deterministik test
        $fixedOtp = '654321';
        $otpRow->update([
            'otp_code_hash' => hash_hmac('sha256', $fixedOtp, config('app.key')),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'locked_at' => null,
        ]);

        $verifyResponse = $this->actingAs($user)->post('/profile/email/verify', [
            'otp_code' => $fixedOtp,
        ]);

        $verifyResponse->assertSessionHas('status', 'profile-email-updated');

        $user->refresh();
        // 4. SWAP BERHASIL
        $this->assertEquals('brand.new@example.test', $user->email);
        $this->assertEquals('brand.new@example.test', $user->email_normalized);
        $this->assertNull($user->pending_email);

        // Sesi perangkat lain dicabut
        $this->assertDatabaseMissing('sessions', ['id' => 'device_session_to_be_purged']);

        // Notifikasi konfirmasi terkirim ke email lama via EmailOutbox
        $this->assertDatabaseHas('email_outbox', [
            'tenant_id' => $tenant->id,
            'recipient_email' => 'initial.email@example.test',
            'event_type' => 'email_change_completed',
        ]);
    }

    public function test_prune_identity_challenges_command_cleans_up_stale_data(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();

        // 1. Stale OTP (>7 hari)
        $staleOtp = EmailVerificationOtp::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'email' => 'old@example.test',
            'email_normalized' => 'old@example.test',
            'otp_code_hash' => hash('sha256', '123456'),
            'action' => 'registration',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->subDays(8),
        ]);

        // 2. Fresh OTP
        $freshOtp = EmailVerificationOtp::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'email' => 'fresh@example.test',
            'email_normalized' => 'fresh@example.test',
            'otp_code_hash' => hash('sha256', '123456'),
            'action' => 'registration',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
        ]);

        // 3. Stale Password Reset Token (>24 jam)
        DB::table('password_reset_tokens')->insert([
            'email' => 'stale.reset@example.test',
            'token' => 'stale_token_123',
            'created_at' => now()->subHours(25),
        ]);

        // 4. Stale Invitation (status pending tetapi expires_at sudah lewat)
        $staleInvitation = UserInvitation::create([
            'tenant_id' => $tenant->id,
            'role_id' => $user->role_id,
            'invited_by_user_id' => $user->id,
            'name' => 'Expired Staf',
            'email' => 'expired.staf@example.test',
            'email_normalized' => 'expired.staf@example.test',
            'token_hash' => hash('sha256', Str::random(32)),
            'status' => 'pending',
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->artisan('identity:prune-challenges')
            ->assertSuccessful();

        // Stale OTP terhapus, Fresh OTP tetap ada
        $this->assertDatabaseMissing('email_verification_otps', ['id' => $staleOtp->id]);
        $this->assertDatabaseHas('email_verification_otps', ['id' => $freshOtp->id]);

        // Stale password reset token terhapus
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'stale.reset@example.test']);

        // Stale invitation berubah status menjadi 'expired'
        $staleInvitation->refresh();
        $this->assertEquals('expired', $staleInvitation->status);
    }
}
