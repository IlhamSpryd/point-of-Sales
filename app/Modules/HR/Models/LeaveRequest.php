<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Approval;
use App\Models\Tenant;
use App\Modules\HR\Enums\LeaveRequestStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $leave_policy_id
 * @property int|null $approval_id
 * @property CarbonInterface $start_date
 * @property CarbonInterface $end_date
 * @property numeric-string $total_days
 * @property string $reason
 * @property string|null $attachment_path
 * @property LeaveRequestStatusEnum $status
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee|null $employee
 * @property-read LeavePolicy|null $policy
 * @property-read Approval|null $approval
 */
class LeaveRequest extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'leave_requests';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'leave_policy_id',
        'approval_id',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'attachment_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'float',
            'status' => LeaveRequestStatusEnum::class,
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
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'approval_id');
    }

    /**
     * @return HasMany<LeaveTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class, 'leave_request_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', LeaveRequestStatusEnum::APPROVED);
    }
}
