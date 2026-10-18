<?php

namespace App\Services;

use App\Exceptions\OtpRateLimitedException;
use App\Models\EmailOutbox;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\DB;

class EmailVerificationOtpService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_FAILED_ATTEMPTS = 5;

    public const COOLDOWN_SECONDS = 60;

    public const HOURLY_LIMIT = 5;

    public const DAILY_LIMIT = 10;

    /**
     * Terbitkan OTP 6-digit untuk user dengan penguncian baris induk User di InnoDB
     * guna menghindari gap-lock deadlock saat request paralel.
     *
     * @throws OtpRateLimitedException
     */
    public function issueOtp(
        User|int $user,
        string $action = 'registration',
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): EmailVerificationOtp {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return DB::transaction(function () use ($userId, $action, $ipAddress, $userAgent) {
            // 1. Kunci baris induk User yang sudah pasti ada (mencegah gap-lock deadlock)
            $lockedUser = User::withoutGlobalScopes()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            // Tentukan target email
            $targetEmail = ($action === 'email_change' && ! empty($lockedUser->pending_email))
                ? $lockedUser->pending_email
                : $lockedUser->email;

            $normalizedEmail = EmailNormalizer::normalize($targetEmail);

            // 2. Evaluasi Throttling & Cooldown
            $now = now();
            $recentOtps = EmailVerificationOtp::where('user_id', $lockedUser->id)
                ->where('action', $action)
                ->where('created_at', '>=', $now->copy()->subDay())
                ->orderByDesc('id')
                ->get();

            $latest = $recentOtps->first();
            if ($latest && $latest->created_at) {
                $secondsSinceLast = (int) ($now->getTimestamp() - $latest->created_at->getTimestamp());
                if ($secondsSinceLast < self::COOLDOWN_SECONDS) {
                    $retryAfter = self::COOLDOWN_SECONDS - $secondsSinceLast;
                    throw new OtpRateLimitedException(
                        "Mohon tunggu {$retryAfter} detik sebelum meminta kode verifikasi baru.",
                        $retryAfter
                    );
                }
            }

            $hourlyCount = $recentOtps->filter(fn ($item) => $item->created_at >= $now->copy()->subHour())->count();
            if ($hourlyCount >= self::HOURLY_LIMIT) {
                throw new OtpRateLimitedException(
                    'Batas permintaan kode verifikasi per jam tercapai. Silakan coba lagi nanti.',
                    3600
                );
            }

            if ($recentOtps->count() >= self::DAILY_LIMIT) {
                throw new OtpRateLimitedException(
                    'Batas harian permintaan kode verifikasi tercapai. Silakan hubungi dukungan pelanggan.',
                    86400
                );
            }

            // 3. Batalkan kode aktif sebelumnya untuk aksi yang sama (superseded)
            EmailVerificationOtp::where('user_id', $lockedUser->id)
                ->where('action', $action)
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->update(['superseded_at' => $now]);

            // 4. Bangkitkan kode acak 6 digit aman secara kriptografis
            $rawCode = sprintf('%06d', random_int(0, 999999));
            $codeHash = hash_hmac('sha256', $rawCode, config('app.key'));

            // 5. Simpan tantangan OTP baru
            $otp = EmailVerificationOtp::create([
                'tenant_id' => $lockedUser->tenant_id,
                'user_id' => $lockedUser->id,
                'email' => $targetEmail,
                'email_normalized' => $normalizedEmail,
                'otp_code_hash' => $codeHash,
                'action' => $action,
                'attempts' => 0,
                'max_attempts' => self::MAX_FAILED_ATTEMPTS,
                'expires_at' => $now->copy()->addMinutes(self::CODE_TTL_MINUTES),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            $otp->rawCode = $rawCode;

            // 6. Masukkan email ke transactional outbox
            EmailOutbox::create([
                'event_type' => 'email_verification_otp',
                'recipient_email' => $targetEmail,
                'subject' => "Kode Verifikasi Apeiron POS: {$rawCode}",
                'body_text' => "Halo {$lockedUser->name},\n\nKode verifikasi akun Anda adalah: {$rawCode}\n\nKode ini berlaku selama 10 menit. Jangan berikan kode ini kepada siapa pun demi keamanan akun Anda.\n\nSalam,\nTim Apeiron POS",
                'tenant_id' => $lockedUser->tenant_id,
                'user_id' => $lockedUser->id,
                'status' => EmailOutbox::STATUS_PENDING,
                'attempts' => 0,
            ]);

            return $otp;
        });
    }

    /**
     * Verifikasi kode OTP dengan penguncian baris induk User dan baris aktif OTP.
     */
    public function verifyOtp(
        User|int $user,
        string $code,
        string $action = 'registration'
    ): OtpVerificationResult {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return DB::transaction(function () use ($userId, $code, $action) {
            // 1. Kunci baris induk User terlebih dahulu
            $lockedUser = User::withoutGlobalScopes()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Kunci baris tantangan OTP aktif
            $otp = EmailVerificationOtp::where('user_id', $lockedUser->id)
                ->where('action', $action)
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $otp) {
                return new OtpVerificationResult(false, OtpVerificationResult::STATUS_NOT_FOUND, null, 0);
            }

            if ($otp->isLocked()) {
                return new OtpVerificationResult(false, OtpVerificationResult::STATUS_LOCKED, $otp, 0);
            }

            if ($otp->isExpired()) {
                return new OtpVerificationResult(false, OtpVerificationResult::STATUS_EXPIRED, $otp, 0);
            }

            // 3. Verifikasi hash secara konstan (timing-safe)
            $expectedHash = hash_hmac('sha256', trim($code), config('app.key'));
            $isCorrect = hash_equals($otp->otp_code_hash, $expectedHash);

            if (! $isCorrect) {
                $otp->attempts++;
                if ($otp->attempts >= $otp->max_attempts) {
                    $otp->locked_at = now();
                }
                $otp->save();

                $remaining = max(0, $otp->max_attempts - $otp->attempts);

                return new OtpVerificationResult(
                    false,
                    $otp->isLocked() ? OtpVerificationResult::STATUS_LOCKED : OtpVerificationResult::STATUS_INVALID_CODE,
                    $otp,
                    $remaining
                );
            }

            // 4. Berhasil: tandai OTP terkonsumsi
            $otp->consumed_at = now();
            $otp->save();

            // 5. Update status verifikasi akun User jika registrasi
            if ($action === 'registration' || empty($lockedUser->email_verified_at)) {
                $lockedUser->email_verified_at = now();
                $lockedUser->resetLoginLockout();
                $lockedUser->save();
            }

            return new OtpVerificationResult(true, OtpVerificationResult::STATUS_VERIFIED, $otp, 0);
        });
    }

    /**
     * Hitung sisa detik cooldown sebelum resend diizinkan.
     */
    public function secondsUntilResendAvailable(User|int $user, string $action = 'registration'): int
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $latest = EmailVerificationOtp::where('user_id', $userId)
            ->where('action', $action)
            ->latest('id')
            ->first();

        if (! $latest || ! $latest->created_at) {
            return 0;
        }

        $elapsed = (int) (now()->getTimestamp() - $latest->created_at->getTimestamp());

        return max(0, self::COOLDOWN_SECONDS - $elapsed);
    }

    public function canResend(User|int $user, string $action = 'registration'): bool
    {
        return $this->secondsUntilResendAvailable($user, $action) === 0;
    }

    public function getActiveOtp(User|int $user, string $action = 'registration'): ?EmailVerificationOtp
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return EmailVerificationOtp::where('user_id', $userId)
            ->where('action', $action)
            ->whereNull('consumed_at')
            ->whereNull('superseded_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }
}
