<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\ReorderSuggestionStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ReorderSuggestion
 *
 * Rekomendasi pemesanan ulang deterministik berdasarkan stok M4, komitmen M5, ROP, dan lead time.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property int|null $preferred_supplier_id
 * @property numeric-string $current_stock
 * @property numeric-string $on_order_po_qty
 * @property numeric-string $open_pr_qty
 * @property numeric-string $net_inventory_position
 * @property numeric-string $daily_consumption_rate
 * @property numeric-string $lead_time_days
 * @property numeric-string $safety_stock
 * @property numeric-string $reorder_point
 * @property numeric-string $target_max_stock
 * @property numeric-string $raw_deficit_qty
 * @property numeric-string $moq
 * @property numeric-string $order_multiple
 * @property numeric-string $suggested_quantity
 * @property string $purchase_uom
 * @property numeric-string $estimated_unit_price
 * @property numeric-string $estimated_total_cost
 * @property numeric-string $priority_score
 * @property ReorderSuggestionStatusEnum $status
 * @property int|null $converted_purchase_request_id
 * @property string|null $dismissed_reason
 * @property CarbonInterface $calculated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ReorderSuggestion extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'reorder_suggestions';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'purchasable_type',
        'purchasable_id',
        'preferred_supplier_id',
        'current_stock',
        'on_order_po_qty',
        'open_pr_qty',
        'net_inventory_position',
        'daily_consumption_rate',
        'lead_time_days',
        'safety_stock',
        'reorder_point',
        'target_max_stock',
        'raw_deficit_qty',
        'moq',
        'order_multiple',
        'suggested_quantity',
        'purchase_uom',
        'estimated_unit_price',
        'estimated_total_cost',
        'priority_score',
        'status',
        'converted_purchase_request_id',
        'dismissed_reason',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReorderSuggestionStatusEnum::class,
            'calculated_at' => 'datetime',
            'current_stock' => 'string',
            'on_order_po_qty' => 'string',
            'open_pr_qty' => 'string',
            'net_inventory_position' => 'string',
            'daily_consumption_rate' => 'string',
            'lead_time_days' => 'string',
            'safety_stock' => 'string',
            'reorder_point' => 'string',
            'target_max_stock' => 'string',
            'raw_deficit_qty' => 'string',
            'moq' => 'string',
            'order_multiple' => 'string',
            'suggested_quantity' => 'string',
            'estimated_unit_price' => 'string',
            'estimated_total_cost' => 'string',
            'priority_score' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function convertedPurchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'converted_purchase_request_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
