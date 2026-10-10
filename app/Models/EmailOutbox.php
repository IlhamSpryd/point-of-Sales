<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain 1 — transactional email outbox. Baris di-insert di dalam transaksi
 * provisioning (ikut commit/rollback), dikirim asinkron oleh command
 * emails:process-outbox.
 */
class EmailOutbox extends Model
{
    public const STATUS_PENDING = 'pending';

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
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
