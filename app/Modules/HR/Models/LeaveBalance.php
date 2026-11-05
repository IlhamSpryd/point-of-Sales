<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $leave_policy_id
 * @property int $year
 * @property float|numeric-string $allocated_days
 * @property float|numeric-string $used_days
 * @property float|numeric-string $remaining_days
 * @property numeric-string $expired_days
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee|null $employee
 * @property-read LeavePolicy|null $policy
 */
class LeaveBalance extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'leave_balances';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'leave_policy_id',
        'year',
        'allocated_days',
        'used_days',
        'remaining_days',
        'expired_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'allocated_days' => 'float',
            'used_days' => 'float',
            'remaining_days' => 'float',
            'expired_days' => 'float',
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
}
