<?php

namespace App\Jobs;

use App\Models\EmailOutbox;
use App\Models\EmailVerificationOtp;
use App\Models\OwnerOnboardingToken;
use App\Services\Context\TenantContext;
use App\Services\ProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Domain 1 — proses SATU baris outbox.
 *
 * Klaim atomik (DB-016 / TX-002): baris `pending` (atau `processing` dengan lease kedaluwarsa)
 * diklaim via conditional UPDATE. Kolom `lease_expires_at` memisahkan lease worker
 * dari jadwal retry `next_attempt_at`.
 *
 * Token onboarding (TX-005 / TX-011): `email_outbox.body_text` dienkripsi at rest
 * dengan AES-256 (`Crypt::encryptString`). Retry pengiriman TIDAK merotasi token,
 * sehingga provider timeout tidak membatalkan tautan yang sudah sampai ke pemilik.
 * Tautan ber-token hanya diredaksi setelah pengiriman berhasil (`STATUS_SENT`).
 */
class ProcessProvisioningOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $outboxId,
        /**
         * Plaintext token onboarding opsional jika diteruskan dari pemanggil.
         */
        public ?string $onboardingToken = null,
    ) {}

    public function handle(): void
    {
        $outbox = $this->claim();
        if ($outbox === null) {
            return; // sudah diproses worker lain / sudah terkirim / belum due
        }

        if (empty($outbox->tenant_id)) {
            Log::error('EmailOutbox record missing tenant_id.', ['outbox_id' => $outbox->id]);

            return;
        }

        $tenantContext = app(TenantContext::class);
        $prevTenantId = $tenantContext->getTenantId();
        $prevStoreId = $tenantContext->getStoreId();

        try {
            $tenantContext->setTenantId((int) $outbox->tenant_id);
            $tenantContext->setStoreId(null);

            $body = $this->resolveAndVerifyBody($outbox);

            Mail::raw($body, function ($message) use ($outbox) {
                $message->to($outbox->recipient_email)
                    ->subject($outbox->subject);
            });

            // Penanda sukses: body disunting (redacted) agar token tidak lagi tersimpan
            // bahkan dalam bentuk terenkripsi (TX-005, TX-011).
            EmailOutbox::whereKey($outbox->id)
                ->where('status', EmailOutbox::STATUS_PROCESSING)
                ->where('attempts', $outbox->attempts)
                ->update([
                    'status' => EmailOutbox::STATUS_SENT,
                    'sent_at' => now(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'lease_expires_at' => null,
                    'body_text' => Crypt::encryptString($this->redactedBody($outbox, $body)),
                ]);
        } catch (\Throwable $e) {
            $this->markFailedAttempt($outbox, $e);

            return;
        } finally {
            $tenantContext->clear();
            if ($prevTenantId !== null) {
                $tenantContext->setTenantId($prevTenantId);
                $tenantContext->setStoreId($prevStoreId);
            }
        }
    }

    /**
     * Klaim atomik. Memilih baris PENDING due ATAU PROCESSING dengan lease kedaluwarsa (TX-002).
     */
    private function claim(): ?EmailOutbox
    {
        $leaseMinutes = max(1, (int) config('pos.provisioning.outbox_lease_minutes', 10));

        $candidate = EmailOutbox::whereKey($this->outboxId)
            ->where(function ($q) {
                $q->where(function ($pending) {
                    $pending->where('status', EmailOutbox::STATUS_PENDING)
                        ->where(function ($time) {
                            $time->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($stale) {
                    $stale->where('status', EmailOutbox::STATUS_PROCESSING)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', now());
                });
            })
            ->first();

        if ($candidate === null) {
            return null;
        }

        $claimed = EmailOutbox::whereKey($candidate->id)
            ->where('status', $candidate->status)
            ->where('attempts', $candidate->attempts)
            ->update([
                'status' => EmailOutbox::STATUS_PROCESSING,
                'attempts' => $candidate->attempts + 1,
                'lease_expires_at' => now()->addMinutes($leaseMinutes),
            ]);

        if ($claimed !== 1) {
            return null; // worker lain menang
        }

        $candidate->status = EmailOutbox::STATUS_PROCESSING;
        $candidate->attempts++;
        $candidate->lease_expires_at = now()->addMinutes($leaseMinutes);

        return $candidate;
    }

    /**
     * Selesaikan dan verifikasi isi email.
     * Retry TIDAK merotasi token (TX-005). Jika tautan memuat hash lama atau token
     * diteruskan via constructor, injeksikan secara konsisten.
     */
    private function resolveAndVerifyBody(EmailOutbox $outbox): string
    {
        $body = $outbox->body_text;

        if ($outbox->event_type === 'email_verification_otp') {
            $otp = null;
            if (preg_match('/(\d{6})/', $outbox->subject.' '.$body, $m)) {
                $codeHash = hash_hmac('sha256', $m[1], config('app.key'));
                $otp = EmailVerificationOtp::where('tenant_id', $outbox->tenant_id)
                    ->where('user_id', $outbox->user_id)
                    ->where('otp_code_hash', $codeHash)
                    ->first();
            }

            if (! $otp) {
                $otp = EmailVerificationOtp::where('tenant_id', $outbox->tenant_id)
                    ->where('user_id', $outbox->user_id)
                    ->where('email', $outbox->recipient_email)
                    ->latest('id')
                    ->first();
            }

            if (! $otp || (! $otp->isValid())) {
                throw new \RuntimeException('otp_challenge_superseded_consumed_or_expired');
            }

            return $body;
        }

        if ($outbox->event_type !== 'owner_onboarding') {
            return $body;
        }

        // Jalur 1: Body sudah memuat plaintext token yang valid
        $rawToken = $this->tokenFromLink($body);
        if ($rawToken !== null) {
            $tokenHash = OwnerOnboardingToken::hash($rawToken);
            $tokenRecord = OwnerOnboardingToken::withoutGlobalScopes()
                ->where('tenant_id', $outbox->tenant_id)
                ->where('user_id', $outbox->user_id)
                ->where('token_hash', $tokenHash)
                ->first();

            if ($tokenRecord !== null) {
                if ($tokenRecord->isExpired()) {
                    throw new \RuntimeException('onboarding_token_expired');
                }

                return $body;
            }
        }

        // Jalur 2: Token diteruskan via payload job ($this->onboardingToken)
        if (is_string($this->onboardingToken) && $this->onboardingToken !== '') {
            $expectedHash = OwnerOnboardingToken::hash($this->onboardingToken);
            $tokenRecord = OwnerOnboardingToken::withoutGlobalScopes()
                ->where('tenant_id', $outbox->tenant_id)
                ->where('user_id', $outbox->user_id)
                ->where('token_hash', $expectedHash)
                ->first();

            if ($tokenRecord !== null && ! $tokenRecord->isExpired()) {
                $hashInBody = $this->hashFromBody($body);
                if ($hashInBody !== null) {
                    return ProvisioningService::injectOnboardingToken($body, $hashInBody, $this->onboardingToken);
                }

                return $body;
            }
        }

        // Jalur 3: Token di database ada tapi body hanya memuat hash (mis. legacy)
        $hashInBody = $this->hashFromBody($body);
        if ($hashInBody !== null) {
            $tokenRecord = OwnerOnboardingToken::withoutGlobalScopes()
                ->where('tenant_id', $outbox->tenant_id)
                ->where('user_id', $outbox->user_id)
                ->where('token_hash', $hashInBody)
                ->first();

            if ($tokenRecord !== null && ! $tokenRecord->isExpired() && ! $tokenRecord->isConsumed()) {
                // Untuk baris legacy yang tidak memiliki plaintext, lakukan reissue terkontrol
                $plaintext = OwnerOnboardingToken::reissueFor($hashInBody, (int) $outbox->tenant_id, (int) $outbox->user_id);
                if ($plaintext !== null) {
                    $newHash = OwnerOnboardingToken::hash($plaintext);

                    return ProvisioningService::injectOnboardingToken($body, $hashInBody, $plaintext);
                }
            }
        }

        return $body;
    }

    private function tokenFromLink(string $body): ?string
    {
        if (preg_match('#/onboarding/set-password/([A-Za-z0-9_-]{40,})#', $body, $m)) {
            // Jika panjangnya 64 hex, kemungkinan itu hash, bukan random plaintext token
            if (preg_match('/^[a-f0-9]{64}$/i', $m[1])) {
                // Cek apakah ada record dengan hash ini
                $hashExists = OwnerOnboardingToken::where('token_hash', strtolower($m[1]))->exists();
                if ($hashExists) {
                    return null; // Ini hash, bukan plaintext
                }
            }

            return $m[1];
        }

        return null;
    }

    private function hashFromBody(string $body): ?string
    {
        if (preg_match('#/onboarding/set-password/([a-f0-9]{64})#', $body, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Versi body yang disimpan setelah pengiriman: token plaintext dihapus dan
     * disubstitusi dengan hash token (TX-005, TX-011).
     */
    private function redactedBody(EmailOutbox $outbox, string $sentBody): string
    {
        $token = $this->tokenFromLink($sentBody);
        if ($token === null) {
            return $sentBody;
        }

        $tokenHash = OwnerOnboardingToken::hash($token);

        return ProvisioningService::injectOnboardingToken($sentBody, $token, $tokenHash);
    }

    private function markFailedAttempt(EmailOutbox $outbox, \Throwable $e): void
    {
        if ($outbox->event_type === 'owner_onboarding') {
            OwnerOnboardingToken::query()
                ->where('tenant_id', $outbox->tenant_id)
                ->where('user_id', $outbox->user_id)
                ->whereNull('consumed_at')
                ->update(['send_attempts' => $outbox->attempts]);
        }

        $attempts = $outbox->attempts;
        $maxAttempts = (int) config('pos.provisioning.outbox_max_attempts', 5);
        $exhausted = $attempts >= $maxAttempts;

        $query = EmailOutbox::whereKey($outbox->id)
            ->where('status', EmailOutbox::STATUS_PROCESSING)
            ->where('attempts', $outbox->attempts);

        if (str_contains($e->getMessage(), 'otp_challenge_superseded')) {
            $query->update([
                'status' => EmailOutbox::STATUS_FAILED,
                'last_error' => 'OTP superseded, consumed, or expired before delivery',
                'next_attempt_at' => null,
                'lease_expires_at' => null,
            ]);

            Log::info('Outbox email OTP dibatalkan karena sudah superseded, consumed, atau expired.', [
                'outbox_id' => $outbox->id,
            ]);

            return;
        }

        if ($exhausted) {
            $query->update([
                'status' => EmailOutbox::STATUS_FAILED,
                'last_error' => $this->safeError($e),
                'next_attempt_at' => null,
                'lease_expires_at' => null,
            ]);

            Log::error('Outbox email gagal permanen setelah batas percobaan.', [
                'outbox_id' => $outbox->id,
                'attempts' => $attempts,
            ]);

            return;
        }

        // Backoff eksponensial (5, 25, 125 menit) dengan jitter 0-30 detik
        $jitter = random_int(0, 30);
        $query->update([
            'status' => EmailOutbox::STATUS_PENDING,
            'last_error' => $this->safeError($e),
            'lease_expires_at' => null,
            'next_attempt_at' => now()->addMinutes(5 ** $attempts)->addSeconds($jitter),
        ]);

        Log::warning('Outbox email gagal dikirim; dijadwalkan ulang.', [
            'outbox_id' => $outbox->id,
            'attempts' => $attempts,
        ]);
    }

    private function safeError(\Throwable $e): string
    {
        // Jangan simpan pesan mentah (dapat memuat kredensial SMTP atau isi pesan)
        return substr($e::class.' during outbox delivery', 0, 120);
    }
}
