<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $shift_code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property bool $cross_midnight
 * @property int $unpaid_break_minutes
 * @property int $grace_period_minutes
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Store|null $store
 */
class ShiftTemplate extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'shift_templates';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'shift_code',
        'name',
        'start_time',
        'end_time',
        'cross_midnight',
        'unpaid_break_minutes',
        'grace_period_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cross_midnight' => 'boolean',
            'unpaid_break_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'is_active' => 'boolean',
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
     * Scope template shift aktif.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Menghitung total durasi kotor shift dalam menit.
     * Mengakomodasi shift lintas tengah malam (cross_midnight).
     */
    public function grossDurationMinutes(): int
    {
        $baseDate = '2026-01-01';
        $start = Carbon::parse("{$baseDate} {$this->start_time}");
        $end = Carbon::parse("{$baseDate} {$this->end_time}");

        if ($this->cross_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return (int) $start->diffInMinutes($end);
    }

    /**
     * Menghitung durasi kerja bersih (netto) dalam menit setelah dikurangi istirahat tidak berbayar.
     */
    public function netWorkDurationMinutes(): int
    {
        $gross = $this->grossDurationMinutes();

        return max(0, $gross - $this->unpaid_break_minutes);
    }

    /**
     * Menghitung durasi kerja bersih dalam jam desimal (contoh: 7.00 jam).
     */
    public function netWorkHours(): float
    {
        return round($this->netWorkDurationMinutes() / 60, 2);
    }
}
