<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $period_name
 * @property CarbonInterface $start_date
 * @property CarbonInterface $end_date
 * @property CarbonInterface $cutoff_date
 * @property CarbonInterface $payment_date
 * @property bool $is_closed
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 */
class PayrollPeriod extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'payroll_periods';

    protected $fillable = [
        'tenant_id',
        'period_name',
        'start_date',
        'end_date',
        'cutoff_date',
        'payment_date',
        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'cutoff_date' => 'date',
            'payment_date' => 'date',
            'is_closed' => 'boolean',
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
     * @return HasMany<PayrollBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(PayrollBatch::class, 'payroll_period_id');
    }
}
