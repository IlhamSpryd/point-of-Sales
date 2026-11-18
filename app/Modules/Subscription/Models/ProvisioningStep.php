<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Step-tracked idempotent provisioning ledger model (EXT-03, R-19).
 * Tracks atomic execution status and error state per onboarding step.
 *
 * @property int $id
 * @property int $provisioning_request_id
 * @property int|null $tenant_id
 * @property string $step_key
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProvisioningStep extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STEP_CREATE_TENANT = 'create_tenant';

    public const STEP_CREATE_COMPANY_AND_BRANCH = 'create_company_and_branch';

    public const STEP_SEED_ROLES_AND_OWNER = 'seed_roles_and_owner';

    public const STEP_SEED_CHART_OF_ACCOUNTS = 'seed_chart_of_accounts';

    public const STEP_ACTIVATE_SUBSCRIPTION_PLAN = 'activate_subscription_plan';

    public const STEP_DISPATCH_WELCOME_NOTIFICATION = 'dispatch_welcome_notification';

    protected $fillable = [
        'provisioning_request_id',
        'tenant_id',
        'step_key',
        'status',
        'attempts',
        'last_error',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProvisioningRequest, $this>
     */
    public function provisioningRequest(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRequest::class, 'provisioning_request_id');
    }

    /**
     * @return BelongsTo<\App\Modules\Organization\Models\Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Organization\Models\Tenant::class, 'tenant_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
