<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int|null $discount_id
 * @property string $status
 * @property array $cart_payload
 * @property Carbon|null $expires_at
 */
class ParkedCart extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    public const STATUS_PARKED = 'parked';

    public const STATUS_RESUMED = 'resumed';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'device_id',
        'shift_id',
        'parked_by_user_id',
        'customer_id',
        'table_id',
        'hold_reference',
        'customer_label',
        'order_type',
        'cart_payload',
        'discount_id',
        'notes',
        'status',
        'expires_at',
        'resumed_by_user_id',
        'converted_order_id',
    ];

    protected function casts(): array
    {
        return [
            'cart_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function parkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parked_by_user_id');
    }

    public function resumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }
}
