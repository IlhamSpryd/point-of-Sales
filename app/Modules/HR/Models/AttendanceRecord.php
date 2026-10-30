<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Device;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\AttendanceSourceEnum;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $employee_id
 * @property int|null $work_schedule_id
 * @property CarbonInterface $clock_in_at
 * @property CarbonInterface|null $clock_out_at
 * @property AttendanceSourceEnum $source
 * @property int|null $device_id
 * @property AttendanceStatusEnum $status
 * @property int $late_minutes
 * @property int $early_departure_minutes
 * @property int $total_break_minutes
 * @property int $net_work_minutes
 * @property bool $is_locked
 * @property string|null $correction_reason
 * @property int|null $corrected_by_user_id
 * @property int|null $approval_id
 * @property string|null $snapshot_url
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Store|null $store
 * @property-read Employee|null $employee
 * @property-read WorkSchedule|null $workSchedule
 * @property-read Device|null $device
 * @property-read User|null $correctedBy
 * @property-read Collection<int, AttendanceBreak> $breaks
 */
class AttendanceRecord extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'attendance_records';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'employee_id',
        'work_schedule_id',
        'clock_in_at',
        'clock_out_at',
        'source',
        'device_id',
        'status',
        'late_minutes',
        'early_departure_minutes',
        'total_break_minutes',
        'net_work_minutes',
        'is_locked',
        'correction_reason',
        'corrected_by_user_id',
        'approval_id',
        'snapshot_url',
        'latitude',
        'longitude',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'source' => AttendanceSourceEnum::class,
            'status' => AttendanceStatusEnum::class,
            'late_minutes' => 'integer',
            'early_departure_minutes' => 'integer',
            'total_break_minutes' => 'integer',
            'net_work_minutes' => 'integer',
            'is_locked' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
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
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }

    /**
     * @return HasMany<AttendanceBreak, $this>
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class, 'attendance_record_id');
    }

    /**
     * @param  Builder<AttendanceRecord>  $query
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clock_out_at');
    }

    /**
     * @param  Builder<AttendanceRecord>  $query
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('clock_out_at');
    }
}
