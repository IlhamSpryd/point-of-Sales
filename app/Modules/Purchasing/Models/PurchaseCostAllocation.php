<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PurchaseCostAllocation
 *
 * Rincian alokasi biaya tambahan per baris GRN.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_additional_cost_id
 * @property int $goods_received_note_line_id
 * @property string $allocated_amount
 * @property string $allocated_cost_per_unit
 * @property string $weight_ratio
 * @property bool $is_residual_line
 * @property bool $is_post_receipt_adjustment
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PurchaseCostAllocation extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_cost_allocations';

    protected $fillable = [
        'tenant_id',
        'purchase_additional_cost_id',
        'goods_received_note_line_id',
        'allocated_amount',
        'allocated_cost_per_unit',
        'weight_ratio',
        'is_residual_line',
        'is_post_receipt_adjustment',
    ];

    protected $casts = [
        'allocated_amount' => 'string',
        'allocated_cost_per_unit' => 'string',
        'weight_ratio' => 'string',
        'is_residual_line' => 'boolean',
        'is_post_receipt_adjustment' => 'boolean',
    ];

    /**
     * @return BelongsTo<PurchaseAdditionalCost, $this>
     */
    public function additionalCost(): BelongsTo
    {
        return $this->belongsTo(PurchaseAdditionalCost::class, 'purchase_additional_cost_id');
    }

    /**
     * @return BelongsTo<GoodsReceivedNoteLine, $this>
     */
    public function goodsReceivedNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteLine::class, 'goods_received_note_line_id');
    }
}
