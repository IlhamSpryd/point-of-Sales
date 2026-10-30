<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\PayrollAdjustmentTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int|null $payroll_batch_id
 * @property PayrollAdjustmentTypeEnum $adjustment_type
 * @property float $amount
 * @property string $reason
 * @property int|null $approval_id
 * @property int $created_by_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee|null $employee
 * @property-read PayrollBatch|null $batch
 * @property-read Approval|null $approval
 * @property-read User|null $creator
 */
class PayrollAdjustment extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'payroll_adjustments';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'payroll_batch_id',
        'adjustment_type',
        'amount',
        'reason',
        'approval_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_type' => PayrollAdjustmentTypeEnum::class,
            'amount' => 'float',
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
     * @return BelongsTo<PayrollBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'approval_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
