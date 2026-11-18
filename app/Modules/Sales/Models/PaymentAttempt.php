<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\PaymentAttemptStatus;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $payment_id
 * @property int|null $device_id
 * @property int|null $shift_id
 * @property int $amount
 * @property PaymentAttemptStatus|string $status
 * @property int|null $initiated_by_user_id
 * @property array<int|string, mixed>|string|null $raw_response_meta
 * @property Carbon|string|null $settled_at
 * @property Carbon|string|null $expired_at
 */
class PaymentAttempt extends Model
{
    use AssignsStore;
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStore;
    use ScopedToTenant;

    protected $fillable = [
        'order_id',
        'payment_id',
        'device_id',
        'shift_id',
        'idempotency_key',
        'provider',
        'payment_method',
        'provider_order_id',
        'provider_transaction_id',
        'amount',
        'currency',
        'status',
        'raw_request_meta',
        'raw_response_meta',
        'initiated_by_user_id',
        'settled_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentAttemptStatus::class,
            'raw_request_meta' => 'array',
            'raw_response_meta' => 'array',
            'settled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
     * @return BelongsTo<User, $this>
     */
    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
