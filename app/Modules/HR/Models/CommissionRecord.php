<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Enums\CommissionRecordStatusEnum;
use App\Modules\HR\Enums\CommissionRecordTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $employee_id
 * @property int $commission_rule_id
 * @property int $order_id
 * @property int|null $order_item_id
 * @property numeric-string $calculation_basis_amount
 * @property numeric-string $commission_amount
 * @property CommissionRecordTypeEnum $type
 * @property int|null $reversal_source_id
 * @property CommissionRecordStatusEnum $status
 * @property int|null $payroll_record_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Store|null $store
 * @property-read Employee|null $employee
 * @property-read CommissionRule|null $rule
 * @property-read Order|null $order
 * @property-read OrderItem|null $orderItem
 * @property-read CommissionRecord|null $reversalSource
 */
class CommissionRecord extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'commission_records';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'employee_id',
        'commission_rule_id',
        'order_id',
        'order_item_id',
        'calculation_basis_amount',
        'commission_amount',
        'type',
        'reversal_source_id',
        'status',
        'payroll_record_id',
    ];

    protected function casts(): array
    {
        return [
            'calculation_basis_amount' => 'float',
            'commission_amount' => 'float',
            'type' => CommissionRecordTypeEnum::class,
            'status' => CommissionRecordStatusEnum::class,
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
     * @return BelongsTo<CommissionRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<CommissionRecord, $this>
     */
    public function reversalSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_source_id');
    }

    /**
     * @return HasMany<CommissionRecord, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_source_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CommissionRecordStatusEnum::PENDING);
    }
}
