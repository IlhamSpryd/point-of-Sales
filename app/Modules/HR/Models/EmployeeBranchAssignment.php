<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $store_id
 * @property AssignmentTypeEnum $assignment_type
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee|null $employee
 * @property-read Store|null $store
 */
class EmployeeBranchAssignment extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'employee_branch_assignments';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'store_id',
        'assignment_type',
        'valid_from',
        'valid_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentTypeEnum::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Scope untuk penugasan aktif pada tanggal tertentu.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return $query->where('valid_from', '<=', $dateStr)
            ->where(function (Builder $q) use ($dateStr) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $dateStr);
            });
    }

    /**
     * Scope penugasan primer.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('assignment_type', AssignmentTypeEnum::PRIMARY);
    }
}
