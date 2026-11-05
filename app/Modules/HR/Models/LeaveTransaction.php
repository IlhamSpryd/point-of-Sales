<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\LeaveTransactionTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $leave_policy_id
 * @property LeaveTransactionTypeEnum $transaction_type
 * @property numeric-string $days_delta
 * @property int|null $leave_request_id
 * @property string $reason
 * @property int $actor_user_id
 * @property CarbonInterface $created_at
 * @property-read Tenant|null $tenant
 * @property-read Employee|null $employee
 * @property-read LeavePolicy|null $policy
 * @property-read LeaveRequest|null $leaveRequest
 * @property-read User|null $actor
 */
class LeaveTransaction extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $table = 'leave_transactions';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'leave_policy_id',
        'transaction_type',
        'days_delta',
        'leave_request_id',
        'reason',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => LeaveTransactionTypeEnum::class,
            'days_delta' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<LeavePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
