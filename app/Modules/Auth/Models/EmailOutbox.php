<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain 1 — transactional email outbox. Baris di-insert di dalam transaksi
 * provisioning (ikut commit/rollback), dikirim asinkron oleh command
 * emails:process-outbox.
 *
 * Klaim atomik (DB-016): baris `pending` di-klaim lewat conditional UPDATE
 * menjadi `processing` sebelum dikirim. Tanpa langkah itu, dua worker dapat
 * membaca baris pending yang sama dan mengirim email dua kali; SELECT saja
 * tidak pernah cukup karena tidak ada penguncian.
 *
 * Catatan retensi (DB-003): baris yang sudah terkirim TIDAK memuat token yang
 * dapat dipakai ulang. Body email yang tersimpan setelah pengiriman adalah
 * versi yang tautannya sudah disunting (hash token disematkan), sehingga dump
 * database tidak pernah mengembalikan token onboarding yang valid.
 */
class EmailOutbox extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Migration membuat tabel 'email_outbox' (singular) — sesuaikan. */
    protected $table = 'email_outbox';

    protected $fillable = [
        'event_type',
        'recipient_email',
        'subject',
        'body_text',
        'tenant_id',
        'user_id',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'lease_expires_at',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'body_text' => 'encrypted',
    ];

    /** Klaim sudah kedaluwarsa (worker pengirim dianggap mati). */
    public function isClaimExpired(): bool
    {
        return $this->lease_expires_at !== null
            && $this->status === self::STATUS_PROCESSING
            && $this->lease_expires_at->isPast();
    }
}
