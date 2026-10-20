<?php

namespace App\Modules\Settings\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain 1 — idempotency ledger untuk request provisioning S2S.
 *
 * Siklus status (lihat docs/architecture/PROVISIONING.md):
 *
 *   pending ──claim atomik──► processing ──commit bisnis sukses──► completed
 *      ▲                          │
 *      │                          └── gagal transient ──► failed (boleh diretry)
 *      └──────── release klaim (reconcile) ────┘
 *
 * Aturan yang ditegakkan kode pemanggil:
 *  - `processing` hanya boleh diubah oleh pemilik klaim: setiap UPDATE
 *    terminal menyertakan `attempt_count` hasil klaim sebagai fencing token,
 *    sehingga worker yang lambat tidak dapat menimpa klaim yang lebih baru.
 *  - `completed` bersifat terminal: tidak ada jalur kode yang menurunkannya.
 *  - `failed` dengan `error_message = 'email_already_in_use'` adalah
 *    kegagalan bisnis PERMANEN (409), bukan transient (boleh diretry).
 */
class ProvisioningRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Penanda kegagalan bisnis permanen (dibedakan dari transient). */
    public const ERROR_EMAIL_ALREADY_IN_USE = 'email_already_in_use';

    protected $fillable = [
        'idempotency_key',
        'request_fingerprint',
        'owner_email',
        'status',
        'result_tenant_id',
        'result_user_id',
        'error_message',
        'started_at',
        'completed_at',
        'attempt_count',
        'correlation_id',
        'lease_expires_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'result_tenant_id');
    }

    /** Klaim sudah kedaluwarsa (worker pemegangnya dianggap mati). */
    public function isClaimExpired(): bool
    {
        return $this->lease_expires_at !== null && $this->lease_expires_at->isPast();
    }

    /** Kegagalan bisnis permanen — retry dengan key sama tidak akan menolong. */
    public function isPermanentlyFailed(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->error_message === self::ERROR_EMAIL_ALREADY_IN_USE;
    }
}
