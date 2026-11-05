<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Tenant;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property VoucherStatusEnum|string $status
 * @property VoucherDiscountTypeEnum|string $discount_type
 * @property numeric-string $discount_value
 * @property numeric-string|null $max_discount_amount
 * @property numeric-string $min_order_amount
 * @property int $max_uses
 * @property int $used_count
 * @property int|null $discount_id
 * @property int|null $customer_id
 * @property int|null $customer_group_id
 * @property int $max_uses_per_customer
 */
class Voucher extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'discount_id',
        'customer_id',
        'customer_group_id',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_order_amount',
        'max_uses',
        'max_uses_per_customer',
        'used_count',
        'valid_from',
        'valid_until',
        'starts_at',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => VoucherStatusEnum::class,
            'discount_type' => VoucherDiscountTypeEnum::class,
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'used_count' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === VoucherStatusEnum::ACTIVE;
    }

    public function getTimesUsedAttribute(): int
    {
        return (int) $this->used_count;
    }

    public function getMaxUsesGlobalAttribute(): ?int
    {
        return $this->max_uses;
    }

    public function getStartsAtAttribute(): ?Carbon
    {
        return $this->valid_from ? Carbon::parse((string) $this->valid_from) : null;
    }

    public function setStartsAtAttribute(mixed $value): void
    {
        $this->attributes['valid_from'] = $value ? Carbon::parse($value) : null;
    }

    public function getExpiresAtAttribute(): ?Carbon
    {
        return $this->valid_until ? Carbon::parse((string) $this->valid_until) : null;
    }

    public function setExpiresAtAttribute(mixed $value): void
    {
        $this->attributes['valid_until'] = $value ? Carbon::parse($value) : null;
    }

    public function isValidNow(): bool
    {
        if ($this->status !== VoucherStatusEnum::ACTIVE) {
            return false;
        }

        $now = Carbon::now();
        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }
}
