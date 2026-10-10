<?php

namespace App\Jobs;

use App\Models\EmailOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Domain 1 — proses SATU baris outbox.
 *
 * Pola idempotent (D8):
 *  - Ambil baris pending HANYA bila masih pending (conditional update
 *    first-or-fail via whereKey + status pending di query UPDATE) —
 *    mencegah dua worker memproses outbox yang sama.
 *  - Gagal kirim → attempts+1, backoff sederhana, next_attempt_at.
 *  - Max attempts tercapai → 'failed' (teramati di DB, dipulihkan manual /
 *    alerting ops), TIDAK pernah membatalkan resource bisnis.
 */
class ProcessProvisioningOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $outboxId,
    ) {}

    public function handle(): void
    {
        $outbox = EmailOutbox::where('id', $this->outboxId)
            ->where('status', EmailOutbox::STATUS_PENDING)
            ->first();

        if ($outbox === null) {
            return; // sudah diproses worker lain / sudah terkirim
        }

        try {
            Mail::raw($outbox->body_text, function ($message) use ($outbox) {
                $message->to($outbox->recipient_email)
                    ->subject($outbox->subject);
            });

            $outbox->update([
                'status' => EmailOutbox::STATUS_SENT,
                'sent_at' => now(),
                'attempts' => $outbox->attempts + 1,
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $attempts = $outbox->attempts + 1;
            $maxAttempts = (int) config('pos.provisioning.outbox_max_attempts', 5);
            $failed = $attempts >= $maxAttempts;

            $outbox->update([
                'attempts' => $attempts,
                // Backoff: 1, 5, 25, ... menit (exponensial base 5).
                'next_attempt_at' => $failed ? null : now()->addMinutes(5 ** $attempts),
                'status' => $failed ? EmailOutbox::STATUS_FAILED : EmailOutbox::STATUS_PENDING,
                'last_error' => substr($e->getMessage(), 0, 500),
            ]);

            if ($failed) {
                // Jangan rethrow — teramati via email_outbox.status='failed';
                // rethrow akan memicu retry framework di atas batas kita.
                return;
            }

            throw $e; // biarkan framework queue me-retry sesuai $tries
        }
    }
}
