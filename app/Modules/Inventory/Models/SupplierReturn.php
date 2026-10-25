<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\SupplierReturnStatusEnum;
use App\Models\GoodsReceipt;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model Dokumen Retur Pembelian ke Supplier (Supplier Return).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $return_number
 * @property string $idempotency_key
 * @property ?int $goods_receipt_id
 * @property ?string $supplier_name
 * @property ?string $supplier_reference
 * @property SupplierReturnStatusEnum $status
 * @property Carbon $returned_at
 * @property ?Carbon $posted_at
 * @property ?Carbon $reversed_at
 * @property int $created_by
 * @property ?int $posted_by
 * @property ?int $reversed_by
 * @property ?string $reason
 * @property ?string $reversal_reason
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SupplierReturn extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'supplier_returns';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'return_number',
        'idempotency_key',
        'goods_receipt_id',
        'supplier_name',
        'supplier_reference',
        'status',
        'returned_at',
        'posted_at',
        'reversed_at',
        'created_by',
        'posted_by',
        'reversed_by',
        'reason',
        'reversal_reason',
        'notes',
    ];

    protected $casts = [
        'status' => SupplierReturnStatusEnum::class,
        'returned_at' => 'datetime',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /**
     * Item detail retur ke supplier.
     *
     * @return HasMany<SupplierReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class, 'supplier_return_id');
    }

    /**
     * Cabang / Toko pengirim retur.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Dokumen penerimaan barang rujukan (opsional).
     *
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
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
     * Pengesah / poster retur supplier.
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
        return $this->status === SupplierReturnStatusEnum::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === SupplierReturnStatusEnum::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === SupplierReturnStatusEnum::Cancelled;
    }

    public function isReversed(): bool
    {
        return $this->status === SupplierReturnStatusEnum::Reversed;
    }

    /**
     * Menghitung total nilai barang yang diretur ke pemasok.
     */
    public function calculateTotalValue(): string
    {
        $total = '0.0000';
        foreach ($this->items as $item) {
            $lineTotal = bcmul((string) $item->quantity, (string) $item->unit_cost, 4);
            $total = bcadd($total, $lineTotal, 4);
        }

        return $total;
    }
}
