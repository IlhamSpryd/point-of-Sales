<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain 1 — token magic link onboarding Owner pertama.
 * Plaintext token TIDAK disimpan; hanya SHA-256 hash.
 */
class OwnerOnboardingToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'send_attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'send_attempts' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
