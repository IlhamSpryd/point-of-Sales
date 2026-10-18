<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_subject
 * @property string|null $provider_email
 * @property string|null $provider_email_normalized
 * @property bool $provider_email_verified
 * @property bool $is_private_relay
 * @property Carbon $linked_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Tenant|null $tenant
 */
class UserOAuthIdentity extends Model
{
    use HasFactory;

    protected $table = 'user_oauth_identities';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'provider',
        'provider_subject',
        'provider_email',
        'provider_email_normalized',
        'provider_email_verified',
        'is_private_relay',
        'linked_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_email_verified' => 'boolean',
            'is_private_relay' => 'boolean',
            'linked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
