<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $batch_number
 * @property int|null $payroll_period_id
 * @property CarbonInterface $period_start_date
 * @property CarbonInterface $period_end_date
 * @property CarbonInterface $cutoff_date
 * @property CarbonInterface $payment_scheduled_date
 * @property PayrollBatchStatusEnum $status
 * @property int $total_employees
 * @property float|numeric-string $total_gross_amount
 * @property float|numeric-string $total_tax_amount
 * @property float|numeric-string $total_bpjs_employer
 * @property float|numeric-string $total_bpjs_employee
 * @property float|numeric-string $total_other_deductions
 * @property float|numeric-string $total_net_amount
 * @property int|null $gl_accrual_journal_id
 * @property int|null $gl_payment_journal_id
 * @property int $created_by_user_id
 * @property int|null $approved_by_user_id
 * @property CarbonInterface|null $finalized_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read PayrollPeriod|null $period
 * @property-read User|null $creator
 * @property-read User|null $approver
 */
class PayrollBatch extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'payroll_batches';

    protected $fillable = [
        'tenant_id',
        'batch_number',
        'payroll_period_id',
        'period_start_date',
        'period_end_date',
        'cutoff_date',
        'payment_scheduled_date',
        'status',
        'total_employees',
        'total_gross_amount',
        'total_tax_amount',
        'total_bpjs_employer',
        'total_bpjs_employee',
        'total_other_deductions',
        'total_net_amount',
        'gl_accrual_journal_id',
        'gl_payment_journal_id',
        'created_by_user_id',
        'approved_by_user_id',
        'finalized_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'cutoff_date' => 'date',
            'payment_scheduled_date' => 'date',
            'status' => PayrollBatchStatusEnum::class,
            'total_employees' => 'integer',
            'total_gross_amount' => 'decimal:2',
            'total_tax_amount' => 'decimal:2',
            'total_bpjs_employer' => 'decimal:2',
            'total_bpjs_employee' => 'decimal:2',
            'total_other_deductions' => 'decimal:2',
            'total_net_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
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
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<PayrollRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(PayrollRecord::class, 'payroll_batch_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFinalized(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PayrollBatchStatusEnum::FINALIZED,
            PayrollBatchStatusEnum::PAYMENT_PENDING,
            PayrollBatchStatusEnum::PAID,
        ]);
    }
}
