<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Device;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $device_id
 * @property int|null $shift_id
 * @property int $printed_by_user_id
 * @property int $print_sequence
 */
class ReceiptPrintLog extends Model
{
    use AssignsStore;
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStore;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_id',
        'device_id',
        'shift_id',
        'printed_by_user_id',
        'print_sequence',
        'is_reprint',
        'reprint_reason',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_reprint' => 'boolean',
            'print_sequence' => 'integer',
            'printed_at' => 'datetime',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
    public function printedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by_user_id');
    }
}
