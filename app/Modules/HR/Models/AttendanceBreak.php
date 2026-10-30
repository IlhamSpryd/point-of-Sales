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
 * @property int $attendance_record_id
 * @property CarbonInterface $break_start_at
 * @property CarbonInterface|null $break_end_at
 * @property int $duration_minutes
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read AttendanceRecord|null $attendanceRecord
 */
class AttendanceBreak extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'attendance_breaks';

    protected $fillable = [
        'tenant_id',
        'attendance_record_id',
        'break_start_at',
        'break_end_at',
        'duration_minutes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'break_start_at' => 'datetime',
            'break_end_at' => 'datetime',
            'duration_minutes' => 'integer',
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
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }
}
