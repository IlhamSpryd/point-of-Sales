<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Enums\WorkScheduleStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $employee_id
 * @property int $shift_template_id
 * @property CarbonInterface $schedule_date
 * @property WorkScheduleStatusEnum $status
 * @property int|null $swapped_with_schedule_id
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Store|null $store
 * @property-read Employee|null $employee
 * @property-read ShiftTemplate|null $shiftTemplate
 * @property-read WorkSchedule|null $swappedWith
 */
class WorkSchedule extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'work_schedules';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'employee_id',
        'shift_template_id',
        'schedule_date',
        'status',
        'swapped_with_schedule_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'status' => WorkScheduleStatusEnum::class,
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    /**
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function swappedWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'swapped_with_schedule_id');
    }

    /**
     * @return HasOne<AttendanceRecord, $this>
     */
    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class, 'work_schedule_id');
    }

    /**
     * @param  Builder<WorkSchedule>  $query
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', WorkScheduleStatusEnum::SCHEDULED);
    }

    /**
     * @param  Builder<WorkSchedule>  $query
     */
    public function scopeForDate(Builder $query, CarbonInterface|string $date): Builder
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return $query->where('schedule_date', $dateStr);
    }

    /**
     * @param  Builder<WorkSchedule>  $query
     */
    public function scopeBetweenDates(Builder $query, CarbonInterface|string $start, CarbonInterface|string $end): Builder
    {
        $startStr = is_string($start) ? $start : $start->toDateString();
        $endStr = is_string($end) ? $end : $end->toDateString();

        return $query->whereBetween('schedule_date', [$startStr, $endStr]);
    }
}
