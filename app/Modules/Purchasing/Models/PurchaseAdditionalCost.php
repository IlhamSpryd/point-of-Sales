<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\CostAllocationMethodEnum;
use App\Enums\PurchaseCostCategoryEnum;
use App\Enums\PurchaseCostStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PurchaseAdditionalCost
 *
 * Pencatatan biaya tambahan pengadaan barang (Freight, Insurance, Customs, Handling, dll).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int|null $goods_received_note_id
 * @property int|null $purchase_order_id
 * @property string $cost_code
 * @property PurchaseCostCategoryEnum $category
 * @property string $description
 * @property string $amount
 * @property string $currency_code
 * @property string $exchange_rate
 * @property CostAllocationMethodEnum $allocation_method
 * @property string|null $vendor_name
 * @property string|null $invoice_reference
 * @property PurchaseCostStatusEnum $status
 * @property CarbonInterface|null $allocated_at
 * @property int $created_by
 * @property int|null $allocated_by
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PurchaseAdditionalCost extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_additional_costs';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'goods_received_note_id',
        'purchase_order_id',
        'cost_code',
        'category',
        'description',
        'amount',
        'currency_code',
        'exchange_rate',
        'allocation_method',
        'vendor_name',
        'invoice_reference',
        'status',
        'allocated_at',
        'created_by',
        'allocated_by',
        'idempotency_key',
        'notes',
    ];

    protected $casts = [
        'category' => PurchaseCostCategoryEnum::class,
        'allocation_method' => CostAllocationMethodEnum::class,
        'status' => PurchaseCostStatusEnum::class,
        'amount' => 'string',
        'exchange_rate' => 'string',
        'allocated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<GoodsReceivedNote, $this>
     */
    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /**
     * @return HasMany<PurchaseCostAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseCostAllocation::class, 'purchase_additional_cost_id');
    }
}
