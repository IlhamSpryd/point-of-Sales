<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Approval;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Enums\OvertimeDayTypeEnum;
use App\Modules\HR\Enums\OvertimeStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $employee_id
 * @property int $attendance_record_id
 * @property int $approval_id
 * @property CarbonInterface $overtime_date
 * @property OvertimeDayTypeEnum $day_type
 * @property CarbonInterface $start_time
 * @property CarbonInterface $end_time
 * @property int $actual_duration_minutes
 * @property float $payable_hours
 * @property float $hourly_rate_basis
 * @property float $calculated_amount
 * @property OvertimeStatusEnum $status
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Store|null $store
 * @property-read Employee|null $employee
 * @property-read AttendanceRecord|null $attendanceRecord
 * @property-read Approval|null $approval
 */
class OvertimeRecord extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'overtime_records';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'employee_id',
        'attendance_record_id',
        'approval_id',
        'overtime_date',
        'day_type',
        'start_time',
        'end_time',
        'actual_duration_minutes',
        'payable_hours',
        'hourly_rate_basis',
        'calculated_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'overtime_date' => 'date',
            'day_type' => OvertimeDayTypeEnum::class,
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'actual_duration_minutes' => 'integer',
            'payable_hours' => 'float',
            'hourly_rate_basis' => 'float',
            'calculated_amount' => 'float',
            'status' => OvertimeStatusEnum::class,
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
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'approval_id');
    }

    /**
     * @param  Builder<OvertimeRecord>  $query
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', OvertimeStatusEnum::APPROVED);
    }

    /**
     * @param  Builder<OvertimeRecord>  $query
     */
    public function scopeBetweenDates(Builder $query, CarbonInterface|string $start, CarbonInterface|string $end): Builder
    {
        $startStr = is_string($start) ? $start : $start->toDateString();
        $endStr = is_string($end) ? $end : $end->toDateString();

        return $query->whereBetween('overtime_date', [$startStr, $endStr]);
    }
}
