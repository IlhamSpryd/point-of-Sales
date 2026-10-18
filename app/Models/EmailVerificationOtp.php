<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $email
 * @property string $email_normalized
 * @property string $otp_code_hash
 * @property string $action
 * @property int $attempts
 * @property int $max_attempts
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property \Illuminate\Support\Carbon|null $superseded_at
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class EmailVerificationOtp extends Model
{
    use HasFactory;

    protected $table = 'email_verification_otps';

    /**
     * Plaintext code property populated only during issuance in-memory (not persisted).
     */
    public ?string $rawCode = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'email',
        'email_normalized',
        'otp_code_hash',
        'action',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'superseded_at',
        'locked_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null || $this->attempts >= $this->max_attempts;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired()
            && ! $this->isConsumed()
            && ! $this->isSuperseded()
            && ! $this->isLocked();
    }
}
