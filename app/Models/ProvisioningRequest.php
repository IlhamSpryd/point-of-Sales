<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain 1 — idempotency ledger untuk request provisioning S2S.
 * Lifecycle didokumentasikan di docs/architecture/PROVISIONING.md dan
 * migration file.
 */
class ProvisioningRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

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
}
