<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * SupplierPerformanceLog
 *
 * Log historis immutable kinerja pengiriman & harga pembelian pemasok.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int $purchase_order_id
 * @property int $goods_received_note_id
 * @property int $goods_received_note_line_id
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property CarbonInterface $expected_delivery_date
 * @property CarbonInterface $received_at
 * @property string|null $order_to_first_receipt_days
 * @property string|null $order_to_full_receipt_days
 * @property string $variance_from_expected_days
 * @property string $ordered_qty
 * @property string $accepted_qty
 * @property string $unit_price
 * @property string $effective_unit_cost
 * @property CarbonInterface $recorded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class SupplierPerformanceLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'supplier_performance_logs';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'supplier_id',
        'purchase_order_id',
        'goods_received_note_id',
        'goods_received_note_line_id',
        'purchasable_type',
        'purchasable_id',
        'expected_delivery_date',
        'received_at',
        'order_to_first_receipt_days',
        'order_to_full_receipt_days',
        'variance_from_expected_days',
        'ordered_qty',
        'accepted_qty',
        'unit_price',
        'effective_unit_cost',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_delivery_date' => 'date',
            'received_at' => 'datetime',
            'recorded_at' => 'datetime',
            'order_to_first_receipt_days' => 'string',
            'order_to_full_receipt_days' => 'string',
            'variance_from_expected_days' => 'string',
            'ordered_qty' => 'string',
            'accepted_qty' => 'string',
            'unit_price' => 'string',
            'effective_unit_cost' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
    }

    public function goodsReceivedNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteLine::class, 'goods_received_note_line_id');
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
