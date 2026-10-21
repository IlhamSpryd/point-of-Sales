<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\GoodsReceiptStatusEnum;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model Dokumen Penerimaan Barang (Goods Receipt / GRN).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $receipt_number
 * @property string $idempotency_key
 * @property ?string $supplier_reference
 * @property ?string $purchase_order_reference
 * @property GoodsReceiptStatusEnum $status
 * @property Carbon $received_at
 * @property ?Carbon $posted_at
 * @property ?Carbon $reversed_at
 * @property int $created_by
 * @property ?int $posted_by
 * @property ?int $reversed_by
 * @property ?string $reversal_reason
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class GoodsReceipt extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'receipt_number',
        'idempotency_key',
        'supplier_reference',
        'purchase_order_reference',
        'status',
        'received_at',
        'posted_at',
        'reversed_at',
        'created_by',
        'posted_by',
        'reversed_by',
        'reversal_reason',
        'notes',
    ];

    protected $casts = [
        'status' => GoodsReceiptStatusEnum::class,
        'received_at' => 'datetime',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /**
     * Item detail penerimaan barang.
     *
     * @return HasMany<GoodsReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    /**
     * Cabang / Toko penerima.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Pembuat draft dokumen.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Pengesah / poster penerimaan barang.
     *
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Pelaku pembalikan (reversal).
     *
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isDraft(): bool
    {
        return $this->status === GoodsReceiptStatusEnum::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === GoodsReceiptStatusEnum::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === GoodsReceiptStatusEnum::Cancelled;
    }

    public function isReversed(): bool
    {
        return $this->status === GoodsReceiptStatusEnum::Reversed;
    }

    /**
     * Menghitung total nilai inbound cost dokumen secara presisi.
     */
    public function calculateTotalValue(): string
    {
        $total = '0.0000';
        foreach ($this->items as $item) {
            $lineTotal = bcmul((string) $item->quantity_received, (string) $item->unit_cost, 4);
            $total = bcadd($total, $lineTotal, 4);
        }

        return $total;
    }
}
