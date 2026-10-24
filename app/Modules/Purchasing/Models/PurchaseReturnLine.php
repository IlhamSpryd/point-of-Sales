<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * PurchaseReturnLine
 *
 * Baris item pengembalian barang dari baris GRN.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_return_id
 * @property int $goods_received_note_line_id
 * @property int|null $purchase_order_line_id
 * @property int $line_number
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property string $return_qty
 * @property string $unit_cost
 * @property string $subtotal
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total_amount
 * @property string|null $reason
 * @property int|null $stock_movement_id
 * @property int|null $ingredient_stock_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseReturnLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_return_lines';

    protected $fillable = [
        'tenant_id',
        'purchase_return_id',
        'goods_received_note_line_id',
        'purchase_order_line_id',
        'line_number',
        'purchasable_type',
        'purchasable_id',
        'return_qty',
        'unit_cost',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'reason',
        'stock_movement_id',
        'ingredient_stock_movement_id',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'return_qty' => 'string',
        'unit_cost' => 'string',
        'subtotal' => 'string',
        'tax_rate' => 'string',
        'tax_amount' => 'string',
        'total_amount' => 'string',
    ];

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    /**
     * @return BelongsTo<GoodsReceivedNoteLine, $this>
     */
    public function goodsReceivedNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteLine::class, 'goods_received_note_line_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
