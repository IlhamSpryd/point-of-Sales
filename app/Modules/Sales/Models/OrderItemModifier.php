<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Modifier;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * [D2-BE-005] Pivot konsumsi modifier pada baris order: order_item_modifiers.
 * Kolom tenant_id + store_id WAJIB terisi (FK komposit Phase 11 / 2026_12_04_000011).
 *
 * @property numeric-string $price_at_time
 * @property int $id
 * @property int $order_item_id
 * @property int $modifier_id
 * @property int $qty
 * @property int $tenant_id
 * @property int $store_id
 */
class OrderItemModifier extends Pivot
{
    use AssignsStore;
    use AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'order_item_modifiers';

    protected $guarded = ['*'];

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_item_id',
        'modifier_id',
        'price_at_time',
        'qty',
    ];

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Modifier, $this>
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
