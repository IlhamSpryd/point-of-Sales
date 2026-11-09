<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\DamageDispositionEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $goods_received_note_id
 * @property int $purchase_order_line_id
 * @property int $line_number
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property string $uom
 * @property numeric-string $uom_conversion_factor
 * @property numeric-string $received_qty
 * @property numeric-string $accepted_qty
 * @property numeric-string $rejected_qty
 * @property numeric-string $damaged_qty
 * @property DamageDispositionEnum|null $damage_disposition
 * @property string|null $rejection_reason
 * @property numeric-string $base_accepted_qty
 * @property numeric-string $unit_cost_snapshot
 * @property numeric-string $allocated_landed_cost_per_unit
 * @property numeric-string $effective_unit_cost
 * @property string|null $batch_number
 * @property CarbonInterface|null $expiry_date
 * @property string|null $storage_location
 * @property int|null $stock_movement_id
 * @property int|null $ingredient_stock_movement_id
 * @property string|null $notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Tenant $tenant
 * @property-read GoodsReceivedNote $goodsReceivedNote
 * @property-read PurchaseOrderLine $purchaseOrderLine
 * @property-read Model $purchasable
 */
class GoodsReceivedNoteLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'goods_received_note_lines';

    protected $fillable = [
        'tenant_id',
        'goods_received_note_id',
        'purchase_order_line_id',
        'line_number',
        'purchasable_type',
        'purchasable_id',
        'uom',
        'uom_conversion_factor',
        'received_qty',
        'accepted_qty',
        'rejected_qty',
        'damaged_qty',
        'damage_disposition',
        'rejection_reason',
        'base_accepted_qty',
        'unit_cost_snapshot',
        'allocated_landed_cost_per_unit',
        'effective_unit_cost',
        'batch_number',
        'expiry_date',
        'storage_location',
        'stock_movement_id',
        'ingredient_stock_movement_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'damage_disposition' => DamageDispositionEnum::class,
            'uom_conversion_factor' => 'string',
            'received_qty' => 'string',
            'accepted_qty' => 'string',
            'rejected_qty' => 'string',
            'damaged_qty' => 'string',
            'base_accepted_qty' => 'string',
            'unit_cost_snapshot' => 'string',
            'allocated_landed_cost_per_unit' => 'string',
            'effective_unit_cost' => 'string',
            'expiry_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<GoodsReceivedNote, $this>
     */
    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
