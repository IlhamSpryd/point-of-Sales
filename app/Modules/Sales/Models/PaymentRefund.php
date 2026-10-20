<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\CashDrawerMovement;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const REQUEST_REQUESTED = 'requested';

    public const REQUEST_APPROVED = 'approved';

    public const REQUEST_PROCESSING = 'processing';

    public const REQUEST_COMPLETED = 'completed';

    public const REQUEST_FAILED = 'failed';

    public const REQUEST_CANCELLED = 'cancelled';

    public const FINANCIAL_UNSETTLED = 'unsettled';

    public const FINANCIAL_SETTLED = 'settled';

    public const FINANCIAL_REVERSED = 'reversed';

    public const PROVIDER_NOT_APPLICABLE = 'not_applicable';

    public const PROVIDER_PENDING = 'pending';

    public const PROVIDER_UNKNOWN_TIMEOUT = 'unknown_timeout';

    public const PROVIDER_SUCCEEDED = 'succeeded';

    public const PROVIDER_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_id',
        'payment_id',
        'order_return_id',
        'shift_id',
        'device_id',
        'idempotency_key',
        'refund_number',
        'amount',
        'currency',
        'original_payment_method',
        'refund_method',
        'is_method_override',
        'method_override_reason',
        'request_status',
        'provider_status',
        'financial_status',
        'provider_refund_reference',
        'cash_drawer_movement_id',
        'reason',
        'requested_by_user_id',
        'approved_by_user_id',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_method_override' => 'boolean',
            'settled_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function cashDrawerMovement(): BelongsTo
    {
        return $this->belongsTo(CashDrawerMovement::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id')->withTrashed();
    }
}
