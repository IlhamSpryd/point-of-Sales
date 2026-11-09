<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Enums\LoyaltyLedgerTypeEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $order_id
 * @property int $points
 * @property int $balance_after
 * @property int|null $created_by
 * @property int $tenant_id
 * @property int|null $store_id
 */
class LoyaltyLedger extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger';

    protected $fillable = [
        'customer_id',
        'order_id',
        'type',
        'points',
        'balance_after',
        'reference',
        'prev_hash',
        'entry_hash',
        'created_by',
        'tenant_id',
        'store_id',
    ];

    protected $casts = [
        'type' => LoyaltyLedgerTypeEnum::class,
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
