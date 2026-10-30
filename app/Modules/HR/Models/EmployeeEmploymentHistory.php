<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property EmploymentStatusEnum|null $from_status
 * @property EmploymentStatusEnum $to_status
 * @property int|null $from_department_id
 * @property int|null $to_department_id
 * @property int|null $from_job_title_id
 * @property int|null $to_job_title_id
 * @property CarbonInterface $effective_date
 * @property string|null $reference_number
 * @property string|null $reason
 * @property int|null $actor_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee|null $employee
 * @property-read Department|null $fromDepartment
 * @property-read Department|null $toDepartment
 * @property-read JobTitle|null $fromJobTitle
 * @property-read JobTitle|null $toJobTitle
 * @property-read User|null $actor
 */
class EmployeeEmploymentHistory extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'employee_employment_histories';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'from_status',
        'to_status',
        'from_department_id',
        'to_department_id',
        'from_job_title_id',
        'to_job_title_id',
        'effective_date',
        'reference_number',
        'reason',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => EmploymentStatusEnum::class,
            'to_status' => EmploymentStatusEnum::class,
            'effective_date' => 'date',
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
     * @return BelongsTo<Department, $this>
     */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /**
     * @return BelongsTo<JobTitle, $this>
     */
    public function fromJobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'from_job_title_id');
    }

    /**
     * @return BelongsTo<JobTitle, $this>
     */
    public function toJobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'to_job_title_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
