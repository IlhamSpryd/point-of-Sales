<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Device;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $shift_id
 * @property int $amount
 * @property int|null $amount_tendered
 * @property int $change_amount
 * @property int|null $processed_by
 * @property int $tenant_id
 * @property int $store_id
 * @property int|null $device_id
 */
class Payment extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'integer',
        'amount_tendered' => 'integer',
        'change_amount' => 'integer',
        'payment_method' => PaymentMethod::class,
        'status' => PaymentStatusEnum::class,
    ];

    /**
     * @return HasOne<PaymentAttempt, $this>
     */
    public function paymentAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function getRefundedAmount(): int
    {
        return (int) $this->refunds()
            ->whereNotIn('request_status', [PaymentRefund::REQUEST_FAILED, PaymentRefund::REQUEST_CANCELLED])
            ->sum('amount');
    }

    public function getRefundableBalance(): int
    {
        return max(0, (int) $this->amount - $this->getRefundedAmount());
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.');
    }

    public function delete()
    {
        throw new \LogicException('Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.');
    }
}
