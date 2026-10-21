<?php

declare(strict_types=1);

// Emergency Backend Hardening | 2026-09-21

namespace App\Modules\Inventory\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $product_id
 * @property int|null $order_id
 * @property int|null $order_item_id
 * @property StockMovementType|string $type
 * @property int $quantity
 * @property string|null $unit_cost
 * @property string|null $reason
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 * @property-read Store|null $store
 * @property-read Order|null $order
 * @property-read OrderItem|null $orderItem
 */
class StockMovement extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'product_id',
        'order_id',
        'order_item_id',
        'type',
        'quantity',
        'unit_cost',
        'reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
