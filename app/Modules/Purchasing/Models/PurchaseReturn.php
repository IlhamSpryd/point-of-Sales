<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\PurchaseReturnStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * PurchaseReturn
 *
 * Header dokumen operasional pengembalian barang dari GRN ke pemasok.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int $goods_received_note_id
 * @property string $return_number
 * @property PurchaseReturnStatusEnum $status
 * @property string $reason
 * @property string $subtotal_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property Carbon|null $returned_at
 * @property Carbon|null $posted_at
 * @property int $created_by
 * @property int|null $posted_by
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseReturn extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_returns';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'supplier_id',
        'goods_received_note_id',
        'return_number',
        'status',
        'reason',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'returned_at',
        'posted_at',
        'created_by',
        'posted_by',
        'idempotency_key',
        'notes',
    ];

    protected $casts = [
        'status' => PurchaseReturnStatusEnum::class,
        'subtotal_amount' => 'string',
        'tax_amount' => 'string',
        'total_amount' => 'string',
        'returned_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

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
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<GoodsReceivedNote, $this>
     */
    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
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
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return HasMany<PurchaseReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'purchase_return_id');
    }

    /**
     * @return HasOne<PurchaseDebitNote, $this>
     */
    public function debitNote(): HasOne
    {
        return $this->hasOne(PurchaseDebitNote::class, 'purchase_return_id');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseReturnStatusEnum::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === PurchaseReturnStatusEnum::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseReturnStatusEnum::Cancelled;
    }
}
