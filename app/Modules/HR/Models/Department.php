<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $department_code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Department|null $parent
 * @property-read Collection<int, Department> $children
 * @property-read Collection<int, JobTitle> $jobTitles
 * @property-read Collection<int, Employee> $employees
 * @property-read Tenant|null $tenant
 */
class Department extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'departments';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (Department $department): void {
            $hasJobTitles = JobTitle::withoutGlobalScopes()
                ->where('tenant_id', $department->tenant_id)
                ->where('department_id', $department->id)
                ->exists();
            $hasEmployees = Employee::withoutGlobalScopes()
                ->where('tenant_id', $department->tenant_id)
                ->where('department_id', $department->id)
                ->exists();

            if (! $hasJobTitles && ! $hasEmployees) {
                static::withoutGlobalScopes()
                    ->where('tenant_id', $department->tenant_id)
                    ->where('parent_id', $department->id)
                    ->update(['parent_id' => null]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * @return HasMany<JobTitle, $this>
     */
    public function jobTitles(): HasMany
    {
        return $this->hasMany(JobTitle::class, 'department_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }
}
