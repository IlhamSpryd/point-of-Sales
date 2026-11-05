<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Enums\ReturnDispositionEnum;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $unit_price_snapshot
 * @property numeric-string $line_discount_reversed
 * @property numeric-string $line_writeoff_or_refund_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property int $product_id
 * @property int $quantity_returned
 * @property int|null $stock_movement_id
 */
class OrderReturnItem extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_return_id',
        'order_item_id',
        'product_id',
        'quantity_returned',
        'unit_price_snapshot',
        'line_discount_reversed',
        'line_writeoff_or_refund_amount',
        'disposition',
        'disposition_reason',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_returned' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'line_discount_reversed' => 'decimal:2',
            'line_writeoff_or_refund_amount' => 'decimal:2',
            'disposition' => ReturnDispositionEnum::class,
        ];
    }

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
