<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $order_id
 * @property int $tenant_id
 */
class ChannelOrderLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id', 'provider', 'external_order_id', 'status', 'payload', 'error_message', 'order_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
