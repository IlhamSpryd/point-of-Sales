<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CONSUMED = 'CONSUMED';
    public const STATUS_VOIDED = 'VOIDED';

    public const ACTION_MANUAL_DISCOUNT = 'MANUAL_DISCOUNT';
    public const ACTION_OPEN_PRICE = 'OPEN_PRICE';
    public const ACTION_VOID_ORDER = 'VOID_ORDER';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'requester_user_id',
        'approver_user_id',
        'action_code',
        'target_entity_type',
        'target_entity_id',
        'amount',
        'reason',
        'status',
        'consumed_at',
        'consumed_by_order_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'consumed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function consumedByOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'consumed_by_order_id');
    }
}
