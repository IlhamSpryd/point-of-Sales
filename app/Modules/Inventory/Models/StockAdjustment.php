<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\StockAdjustmentReasonEnum;
use App\Enums\StockAdjustmentStatusEnum;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model Dokumen Penyesuaian Stok Terstruktur (Stock Adjustment).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $adjustment_number
 * @property string $idempotency_key
 * @property StockAdjustmentStatusEnum $status
 * @property StockAdjustmentReasonEnum $reason_code
 * @property Carbon $adjusted_at
 * @property ?Carbon $posted_at
 * @property int $created_by
 * @property ?int $posted_by
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class StockAdjustment extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'stock_adjustments';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'adjustment_number',
        'idempotency_key',
        'status',
        'reason_code',
        'adjusted_at',
        'posted_at',
        'created_by',
        'posted_by',
        'notes',
    ];

    protected $casts = [
        'status' => StockAdjustmentStatusEnum::class,
        'reason_code' => StockAdjustmentReasonEnum::class,
        'adjusted_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /**
     * Item detail penyesuaian stok.
     *
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }

    /**
     * Cabang / Toko terkait.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Pembuat dokumen penyesuaian.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Pengesah / poster penyesuaian.
     *
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === StockAdjustmentStatusEnum::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === StockAdjustmentStatusEnum::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === StockAdjustmentStatusEnum::Cancelled;
    }

    /**
     * Menghitung total nilai penyesuaian (kuantitas * unit_cost_snapshot).
     */
    public function calculateTotalValue(): string
    {
        $total = '0.0000';
        foreach ($this->items as $item) {
            $lineTotal = bcmul((string) $item->quantity, (string) $item->unit_cost_snapshot, 4);
            $total = bcadd($total, $lineTotal, 4);
        }

        return $total;
    }
}
