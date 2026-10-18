<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $role_id
 * @property int $invited_by_user_id
 * @property string $email
 * @property string $email_normalized
 * @property string $name
 * @property string $token_hash
 * @property string $status
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $superseded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserInvitation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_BLOCKED_CROSS_TENANT = 'blocked_cross_tenant';

    protected $table = 'user_invitations';

    /**
     * Plaintext token generated only during creation (not stored in DB).
     */
    public ?string $plaintextToken = null;

    protected $fillable = [
        'tenant_id',
        'role_id',
        'invited_by_user_id',
        'email',
        'email_normalized',
        'name',
        'token_hash',
        'status',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'user_invitation_stores');
    }

    public function invitationStores(): HasMany
    {
        return $this->hasMany(UserInvitationStore::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->accepted_at === null
            && $this->revoked_at === null
            && $this->superseded_at === null
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
