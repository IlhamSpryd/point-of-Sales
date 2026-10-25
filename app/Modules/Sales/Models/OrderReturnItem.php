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

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
