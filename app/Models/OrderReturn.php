<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderReturn extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_FULL = 'full';

    public const TYPE_PARTIAL = 'partial';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_id',
        'shift_id',
        'device_id',
        'return_number',
        'idempotency_key',
        'status',
        'return_type',
        'subtotal_returned',
        'discount_reversed',
        'tax_returned',
        'service_charge_returned',
        'total_refundable_amount',
        'reason',
        'requested_by_user_id',
        'approved_by_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_returned' => 'decimal:2',
            'discount_reversed' => 'decimal:2',
            'tax_returned' => 'decimal:2',
            'service_charge_returned' => 'decimal:2',
            'total_refundable_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
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
