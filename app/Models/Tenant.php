<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    public const ONBOARDING_PENDING = 'pending';
    public const ONBOARDING_PENDING_VERIFICATION = 'pending_verification';
    public const ONBOARDING_BUSINESS_PROFILE = 'onboarding_business_profile';
    public const ONBOARDING_INITIAL_BRANCH = 'onboarding_initial_branch';
    public const ONBOARDING_COMPLETED = 'completed';

    public const ACCESS_PENDING = 'pending';
    public const ACCESS_PROVISIONING = 'provisioning';
    public const ACCESS_ACTIVE = 'active';
    public const ACCESS_SUSPENDED = 'suspended';
    public const ACCESS_SUSPENDED_ADMIN = 'suspended_admin';
    public const ACCESS_SUSPENDED_BILLING = 'suspended_billing';
    public const ACCESS_OFFBOARDED = 'offboarded';

    public const SUBSCRIPTION_TRIAL = 'trialing';
    public const SUBSCRIPTION_TRIALING = 'trialing';
    public const SUBSCRIPTION_ACTIVE = 'active';
    public const SUBSCRIPTION_PAST_DUE = 'past_due';
    public const SUBSCRIPTION_CANCELED = 'canceled';
    public const SUBSCRIPTION_UNPAID = 'unpaid';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'onboarding_status',
        'onboarding_completed_at',
        'access_status',
        'subscription_status',
        'subscription_plan',
        'country_code',
        'provisioning_idempotency_key',
        'readiness_status',
        'readiness_checked_at',
    ];

    protected $casts = [
        'onboarding_completed_at' => 'datetime',
        'readiness_checked_at' => 'datetime',
    ];

    public function isOnboardingCompleted(): bool
    {
        return $this->onboarding_status === self::ONBOARDING_COMPLETED;
    }

    public function isOperational(): bool
    {
        return $this->isOnboardingCompleted() && $this->access_status === self::ACCESS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return in_array($this->access_status, [self::ACCESS_SUSPENDED, self::ACCESS_SUSPENDED_ADMIN, self::ACCESS_SUSPENDED_BILLING], true)
            || $this->status === 'suspended';
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function stores()
    {
        return $this->hasMany(Store::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
