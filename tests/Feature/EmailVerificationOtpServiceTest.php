<?php

namespace Tests\Feature;

use App\Exceptions\OtpRateLimitedException;
use App\Jobs\ProcessProvisioningOutboxJob;
use App\Models\EmailOutbox;
use App\Models\EmailVerificationOtp;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
use App\Services\OtpVerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationOtpServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EmailVerificationOtpService $service;
    protected User $user;
    protected Tenant $tenant;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->service = app(EmailVerificationOtpService::class);

        $this->tenant = Tenant::create([
            'name' => 'OTP Test Tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_PENDING_VERIFICATION,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi Owner',
            'email' => 'budi.owner@example.com',
            'password_hash' => bcrypt('password123'),
            'is_active' => true,
        ]);
    }

    public function test_issue_otp_generates_six_digit_code_and_creates_outbox_record(): void
    {
        $otp = $this->service->issueOtp($this->user, 'registration', '127.0.0.1', 'PHPUnit');

        $this->assertNotNull($otp->rawCode);
        $this->assertSame(6, strlen($otp->rawCode));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp->rawCode);

        // Code hash must match SHA-256 HMAC of raw code
        $expectedHash = hash_hmac('sha256', $otp->rawCode, config('app.key'));
        $this->assertSame($expectedHash, $otp->otp_code_hash);

        // Verification challenges must have 10-minute expiry
        $this->assertTrue($otp->expires_at->isFuture());
        $this->assertGreaterThan(now()->addMinutes(9), $otp->expires_at);

        // Must write row into transactional email outbox
        $outbox = EmailOutbox::where('event_type', 'email_verification_otp')
            ->where('recipient_email', 'budi.owner@example.com')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame(EmailOutbox::STATUS_PENDING, $outbox->status);
        $this->assertStringContainsString($otp->rawCode, $outbox->subject);
    }

    public function test_verify_otp_with_valid_code_succeeds_and_verifies_user(): void
    {
        $otp = $this->service->issueOtp($this->user, 'registration');
        $code = $otp->rawCode;

        $result = $this->service->verifyOtp($this->user, $code, 'registration');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(OtpVerificationResult::STATUS_VERIFIED, $result->status);

        $this->assertNotNull($otp->fresh()->consumed_at);
        $this->assertNotNull($this->user->fresh()->email_verified_at);
    }

    public function test_verify_otp_with_invalid_code_increments_attempts_and_returns_invalid(): void
    {
        $otp = $this->service->issueOtp($this->user, 'registration');
        $wrongCode = $otp->rawCode === '123456' ? '654321' : '123456';

        $result = $this->service->verifyOtp($this->user, $wrongCode, 'registration');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(OtpVerificationResult::STATUS_INVALID_CODE, $result->status);
        $this->assertSame(4, $result->attemptsRemaining);

        $this->assertSame(1, $otp->fresh()->attempts);
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_verify_otp_locks_after_five_failed_attempts(): void
    {
        $otp = $this->service->issueOtp($this->user, 'registration');
        $correctCode = $otp->rawCode;
        $wrongCode = $correctCode === '111111' ? '222222' : '111111';

        // 4 failed attempts
        for ($i = 1; $i <= 4; $i++) {
            $res = $this->service->verifyOtp($this->user, $wrongCode, 'registration');
            $this->assertFalse($res->isSuccessful());
            $this->assertSame(OtpVerificationResult::STATUS_INVALID_CODE, $res->status);
        }

        // 5th failed attempt: triggers lockout
        $res5 = $this->service->verifyOtp($this->user, $wrongCode, 'registration');
        $this->assertFalse($res5->isSuccessful());
        $this->assertSame(OtpVerificationResult::STATUS_LOCKED, $res5->status);
        $this->assertNotNull($otp->fresh()->locked_at);

        // 6th attempt with CORRECT code: still rejected because locked
        $res6 = $this->service->verifyOtp($this->user, $correctCode, 'registration');
        $this->assertFalse($res6->isSuccessful());
        $this->assertSame(OtpVerificationResult::STATUS_LOCKED, $res6->status);
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_verify_otp_fails_when_expired(): void
    {
        $otp = $this->service->issueOtp($this->user, 'registration');
        $code = $otp->rawCode;

        // Travel 11 minutes into the future
        Carbon::setTestNow(now()->addMinutes(11));

        $result = $this->service->verifyOtp($this->user, $code, 'registration');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(OtpVerificationResult::STATUS_EXPIRED, $result->status);
        $this->assertNull($this->user->fresh()->email_verified_at);

        Carbon::setTestNow();
    }

    public function test_issuing_new_otp_supersedes_previous_active_otp(): void
    {
        $otp1 = $this->service->issueOtp($this->user, 'registration');
        $code1 = $otp1->rawCode;

        // Travel 65 seconds to clear cooldown
        Carbon::setTestNow(now()->addSeconds(65));

        $otp2 = $this->service->issueOtp($this->user, 'registration');
        $code2 = $otp2->rawCode;

        // OTP 1 must be marked superseded
        $this->assertNotNull($otp1->fresh()->superseded_at);

        // Verifying OTP 1 code fails
        $resOld = $this->service->verifyOtp($this->user, $code1, 'registration');
        $this->assertFalse($resOld->isSuccessful());

        // Verifying OTP 2 code succeeds
        $resNew = $this->service->verifyOtp($this->user, $code2, 'registration');
        $this->assertTrue($resNew->isSuccessful());
        $this->assertSame(OtpVerificationResult::STATUS_VERIFIED, $resNew->status);

        Carbon::setTestNow();
    }

    public function test_cooldown_throttles_rapid_resend_within_sixty_seconds(): void
    {
        $this->service->issueOtp($this->user, 'registration');

        $this->expectException(OtpRateLimitedException::class);
        $this->service->issueOtp($this->user, 'registration');
    }

    public function test_hourly_rate_limit_enforces_maximum_five_otps_per_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(now()->addSeconds(65));
            $this->service->issueOtp($this->user, 'registration');
        }

        Carbon::setTestNow(now()->addSeconds(65));
        $this->expectException(OtpRateLimitedException::class);
        $this->service->issueOtp($this->user, 'registration');

        Carbon::setTestNow();
    }

    public function test_outbox_processor_cancels_superseded_otp_emails(): void
    {
        Mail::fake();

        $otp1 = $this->service->issueOtp($this->user, 'registration');
        $outbox1 = EmailOutbox::where('event_type', 'email_verification_otp')->latest('id')->first();

        // Advance 65 seconds and issue new OTP, superseding OTP 1
        Carbon::setTestNow(now()->addSeconds(65));
        $this->service->issueOtp($this->user, 'registration');

        // Process outbox for the first superseded OTP
        $job = new ProcessProvisioningOutboxJob($outbox1->id);
        $job->handle();

        // Must be marked failed due to being superseded, and NO email dispatched
        $outbox1->refresh();
        $this->assertSame(EmailOutbox::STATUS_FAILED, $outbox1->status);
        $this->assertStringContainsString('superseded', strtolower($outbox1->last_error));

        Mail::assertNothingSent();
        Carbon::setTestNow();
    }
}
