<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount_discounted
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $discount_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property int|null $redeemed_by_user_id
 */
class DiscountRedemption extends Model
{
    use AssignsStore;
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStore;
    use ScopedToTenant;

    public const STATUS_APPLIED = 'applied';

    public const STATUS_VOIDED_REVERSED = 'voided_reversed';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'discount_id',
        'order_id',
        'customer_id',
        'redeemed_by_user_id',
        'discount_code_snapshot',
        'amount_discounted',
        'status',
        'redeemed_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_discounted' => 'decimal:2',
            'redeemed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function redeemedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }
}
