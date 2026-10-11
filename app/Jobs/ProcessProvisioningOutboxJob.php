<?php

namespace App\Jobs;

use App\Models\EmailOutbox;
use App\Models\OwnerOnboardingToken;
use App\Services\ProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Domain 1 — proses SATU baris outbox.
 *
 * Klaim atomik (DB-016): baris `pending` diubah menjadi `processing` lewat
 * conditional UPDATE, dan hanya worker yang berhasil (1 baris terpengaruh)
 * yang boleh mengirim. SELECT-saja tidak pernah cukup: dua worker dapat
 * membaca baris pending yang sama sebelum salah satu menulis.
 *
 * Token onboarding (DB-003): plaintext token TIDAK disimpan di baris outbox —
 * body yang tersimpan memuat hash-nya. Plaintext diturunkan saat pengiriman
 * dari payload job, atau (bila scheduler men-dispatch ulang) dari
 * `owner_onboarding_tokens` yang cocok dengan hash di body. Jadi selama baris
 * menunggu/gagal, database hanya memuat tautan ber-hash.
 *
 * Retry (next_attempt_at + batas percobaan): job TIDAK melempar exception
 * selama masih di bawah batas, karena melempar akan memicu retry framework
 * yang mengabaikan next_attempt_at. Penjadwalan ulang dilakukan dengan
 * menulis status `pending` + next_attempt_at, dan `emails:process-outbox`
 * yang men-pick baris yang sudah jatuh tempo.
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
         * Plaintext token onboarding (hanya ada pada dispatch pasca-commit).
         * TIDAK pernah dipersistensi; hilang bila worker mati, dan diturunkan
         * ulang dari hash di database pada percobaan berikutnya.
         */
        public ?string $onboardingToken = null,
    ) {}

    public function handle(): void
    {
        $outbox = $this->claim();
        if ($outbox === null) {
            return; // sudah diproses worker lain / sudah terkirim / belum due
        }

        try {
            $plaintext = $this->resolvePlaintextToken($outbox);
            if ($outbox->event_type === 'owner_onboarding' && $plaintext === null) {
                throw new \RuntimeException('onboarding_token_unavailable');
            }
            $body = $this->renderBody($outbox, $plaintext);

            Mail::raw($body, function ($message) use ($outbox) {
                $message->to($outbox->recipient_email)
                    ->subject($outbox->subject);
            });
        } catch (\Throwable $e) {
            $this->markFailedAttempt($outbox, $e);

            return;
        }

        // Penanda sukses. Body diganti versi ber-hash (tanpa plaintext) agar
        // nilai yang menganggur di database tidak pernah menjadi kredensial
        // yang bisa dipakai ulang.
        EmailOutbox::whereKey($outbox->id)
            ->where('status', EmailOutbox::STATUS_PROCESSING)
            ->where('attempts', $outbox->attempts)
            ->update([
                'status' => EmailOutbox::STATUS_SENT,
                'sent_at' => now(),
                'last_error' => null,
                'next_attempt_at' => null,
                'body_text' => encrypt($this->redactedBody($outbox)),
            ]);
    }

    /**
     * Klaim atomik. Baris `processing` yang lease-nya kedaluwarsa (worker mati
     * di tengah pengiriman) boleh diambil alih — tanpa itu, satu crash
     * permanen membuat email tidak pernah terkirim.
     */
    private function claim(): ?EmailOutbox
    {
        $leaseMinutes = max(1, (int) config('pos.provisioning.outbox_lease_minutes', 10));

        $candidate = EmailOutbox::whereKey($this->outboxId)
            ->where(function ($q) use ($leaseMinutes) {
                $q->where(function ($q2) {
                    $q2->where('status', EmailOutbox::STATUS_PENDING)
                        ->where(function ($q3) {
                            $q3->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($q2) use ($leaseMinutes) {
                    $q2->where('status', EmailOutbox::STATUS_PROCESSING)
                        ->whereNotNull('next_attempt_at')
                        ->where('next_attempt_at', '<=', now());
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
                'next_attempt_at' => now()->addMinutes($leaseMinutes),
            ]);

        if ($claimed !== 1) {
            return null; // worker lain menang
        }

        $candidate->status = EmailOutbox::STATUS_PROCESSING;
        $candidate->attempts++;

        return $candidate;
    }

    /**
     * Plaintext token: dari payload job bila ada (jalur normal), atau
     * diturunkan dari hash yang tertulis di body (jalur pemulihan scheduler).
     * Pada jalur pemulihan, token dirotasi lebih dulu sehingga hash lama
     * berhenti berlaku — token yang mungkin sudah terkirim tidak dapat
     * dipakai lagi.
     */
    private function resolvePlaintextToken(EmailOutbox $outbox): ?string
    {
        if ($outbox->event_type !== 'owner_onboarding') {
            return null;
        }

        if (is_string($this->onboardingToken) && $this->onboardingToken !== '') {
            $tokenHash = $this->hashFromBody($outbox->body_text);
            if ($tokenHash !== null && hash_equals($tokenHash, OwnerOnboardingToken::hash($this->onboardingToken))) {
                return $this->onboardingToken;
            }

            return null;
        }

        $tokenHash = $this->hashFromBody($outbox->body_text);
        if ($tokenHash === null) {
            return null;
        }

        return DB::transaction(function () use ($outbox, $tokenHash) {
            $plaintext = OwnerOnboardingToken::reissueFor($tokenHash, (int) $outbox->tenant_id, (int) $outbox->user_id);
            if ($plaintext === null) {
                return null;
            }

            $newHash = OwnerOnboardingToken::hash($plaintext);
            $body = ProvisioningService::injectOnboardingToken($outbox->body_text, $tokenHash, $newHash);
            $affected = DB::table('email_outbox')->where('id', $outbox->id)
                ->where('status', EmailOutbox::STATUS_PROCESSING)
                ->where('attempts', $outbox->attempts)
                ->update(['body_text' => encrypt($body), 'updated_at' => now()]);
            if ($affected !== 1) {
                throw new \RuntimeException('outbox_claim_lost');
            }
            $outbox->setAttribute('body_text', $body);

            return $plaintext;
        });
    }

    private function renderBody(EmailOutbox $outbox, ?string $plaintext): string
    {
        $tokenHash = $this->hashFromBody($outbox->body_text);

        // Tanpa token yang bisa disisipkan, kirim body apa adanya. Lebih baik
        // terkirim tanpa tautan daripada gagal permanen.
        if ($plaintext === null || $tokenHash === null) {
            return $outbox->body_text;
        }

        $currentHash = OwnerOnboardingToken::hash($plaintext);
        if (! hash_equals($tokenHash, $currentHash)) {
            // A fresh job payload is valid only for the hash originally bound
            // to this outbox event; never send an unpersisted token.
            return $outbox->body_text;
        }

        return ProvisioningService::injectOnboardingToken($outbox->body_text, $tokenHash, $plaintext);
    }

    /**
     * Versi body yang disimpan setelah pengiriman: tautan sudah tidak memuat
     * plaintext. Dinormalkan ke bentuk ber-hash agar konsisten.
     */
    private function redactedBody(EmailOutbox $outbox): string
    {
        $tokenHash = $this->hashFromBody($outbox->body_text);

        if ($tokenHash === null) {
            return $outbox->body_text;
        }

        return ProvisioningService::injectOnboardingToken($outbox->body_text, $tokenHash, $tokenHash);
    }

    private function hashFromBody(string $body): ?string
    {
        if (! preg_match('#/onboarding/set-password/([a-f0-9]{64})#', $body, $m)) {
            return null;
        }

        return $m[1];
    }

    private function markFailedAttempt(EmailOutbox $outbox, \Throwable $e): void
    {
        if ($outbox->event_type === 'owner_onboarding') {
            // Percobaan ini menghabiskan satu plaintext token (dialokasikan
            // sebelum pengiriman). Catat supaya kebijakan token sekali-pakai
            // dapat diaudit, dan supaya token dapat dirotasi saat percobaan
            // berikutnya.
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

        if ($exhausted) {
            $query->update([
                'status' => EmailOutbox::STATUS_FAILED,
                'last_error' => $this->safeError($e),
                'next_attempt_at' => null,
            ]);

            Log::error('Outbox email gagal permanen setelah batas percobaan.', [
                'outbox_id' => $outbox->id,
                'attempts' => $attempts,
            ]);

            return;
        }

        // Backoff eksponensial: 5, 25, 125 menit (base 5).
        $query->update([
            'status' => EmailOutbox::STATUS_PENDING,
            'last_error' => $this->safeError($e),
            'next_attempt_at' => now()->addMinutes(5 ** $attempts),
        ]);

        Log::warning('Outbox email gagal dikirim; dijadwalkan ulang.', [
            'outbox_id' => $outbox->id,
            'attempts' => $attempts,
        ]);
    }

    private function safeError(\Throwable $e): string
    {
        // Jangan simpan pesan mentah (dapat memuat kredensial SMTP atau isi
        // pesan). Cukup jenis kegagalannya.
        return substr($e::class.' during outbox delivery', 0, 120);
    }
}
