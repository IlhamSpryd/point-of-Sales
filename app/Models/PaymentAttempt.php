<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use AssignsStore;
    use AssignsTenant;
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
