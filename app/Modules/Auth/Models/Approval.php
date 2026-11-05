<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $requester_user_id
 * @property int $approver_user_id
 * @property int|null $target_entity_id
 * @property int|null $consumed_by_order_id
 */
class Approval extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function consumedByOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'consumed_by_order_id');
    }
}
