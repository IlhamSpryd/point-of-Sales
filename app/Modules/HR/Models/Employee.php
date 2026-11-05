<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $employee_number
 * @property string|null $national_id_number
 * @property string $full_name
 * @property string|null $nickname
 * @property string|null $email
 * @property string $phone_number
 * @property GenderEnum $gender
 * @property string|null $birth_place
 * @property CarbonInterface|null $birth_date
 * @property ReligionEnum|null $religion
 * @property MaritalStatusEnum $marital_status
 * @property string|null $residential_address
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $emergency_contact_relation
 * @property int $department_id
 * @property int $job_title_id
 * @property int|null $manager_id
 * @property EmploymentTypeEnum $employment_type
 * @property EmploymentStatusEnum $employment_status
 * @property CarbonInterface $hire_date
 * @property CarbonInterface|null $probation_end_date
 * @property CarbonInterface|null $contract_start_date
 * @property CarbonInterface|null $contract_end_date
 * @property CarbonInterface|null $termination_date
 * @property string|null $termination_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Tenant|null $tenant
 * @property-read User|null $user
 * @property-read Department $department
 * @property-read JobTitle $jobTitle
 * @property-read Employee|null $manager
 * @property-read Collection<int, Employee> $directReports
 * @property-read EmployeeTaxProfile|null $taxProfile
 * @property-read EmployeeBankAccount|null $bankAccount
 * @property-read EmployeeBpjsProfile|null $bpjsProfile
 * @property-read Collection<int, EmployeeBranchAssignment> $branchAssignments
 * @property-read EmployeeBranchAssignment|null $primaryBranchAssignment
 * @property-read Collection<int, EmployeeEmploymentHistory> $employmentHistories
 */
class Employee extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'employees';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (Employee $employee): void {
            if ($employee->isForceDeleting()) {
                static::withoutGlobalScopes()
                    ->where('tenant_id', $employee->tenant_id)
                    ->where('manager_id', $employee->id)
                    ->update(['manager_id' => null]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'national_id_number' => 'encrypted',
            'gender' => GenderEnum::class,
            'religion' => ReligionEnum::class,
            'marital_status' => MaritalStatusEnum::class,
            'employment_type' => EmploymentTypeEnum::class,
            'employment_status' => EmploymentStatusEnum::class,
            'birth_date' => 'date',
            'hire_date' => 'date',
            'probation_end_date' => 'date',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'termination_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return BelongsTo<JobTitle, $this>
     */
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    /**
     * @return HasOne<EmployeeTaxProfile, $this>
     */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(EmployeeTaxProfile::class, 'employee_id');
    }

    /**
     * @return HasOne<EmployeeBankAccount, $this>
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(EmployeeBankAccount::class, 'employee_id');
    }

    /**
     * @return HasOne<EmployeeBpjsProfile, $this>
     */
    public function bpjsProfile(): HasOne
    {
        return $this->hasOne(EmployeeBpjsProfile::class, 'employee_id');
    }

    /**
     * @return HasMany<EmployeeBranchAssignment, $this>
     */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class, 'employee_id');
    }

    /**
     * @return HasOne<EmployeeBranchAssignment, $this>
     */
    public function primaryBranchAssignment(): HasOne
    {
        return $this->hasOne(EmployeeBranchAssignment::class, 'employee_id')
            ->where('assignment_type', AssignmentTypeEnum::PRIMARY)
            ->where('valid_from', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', now()->toDateString());
            })
            ->latest('valid_from');
    }

    /**
     * @return HasMany<EmployeeEmploymentHistory, $this>
     */
    public function employmentHistories(): HasMany
    {
        return $this->hasMany(EmployeeEmploymentHistory::class, 'employee_id');
    }

    /**
     * Penyamaran NIK untuk tampilan UI (UU PDP No. 27/2022).
     * Contoh: 317102********0001
     */
    public function maskedNationalId(): string
    {
        $nik = $this->national_id_number;
        if (empty($nik)) {
            return '-';
        }

        $length = strlen($nik);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($nik, 0, 6).str_repeat('*', $length - 10).substr($nik, -4);
    }

    public function isActive(): bool
    {
        return $this->employment_status === EmploymentStatusEnum::ACTIVE;
    }

    public function isOnProbation(): bool
    {
        return $this->employment_status === EmploymentStatusEnum::ON_PROBATION;
    }
}
