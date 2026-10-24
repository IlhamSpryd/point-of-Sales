<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Enums\StockTransferStatusEnum;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model Dokumen Transfer Stok Antarcabang (Stock Transfer).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $from_store_id
 * @property int $to_store_id
 * @property string $transfer_number
 * @property string $ship_idempotency_key
 * @property ?string $receive_idempotency_key
 * @property StockTransferStatusEnum $status
 * @property ?Carbon $shipped_at
 * @property ?Carbon $received_at
 * @property ?Carbon $cancelled_at
 * @property int $created_by
 * @property ?int $shipped_by
 * @property ?int $received_by
 * @property ?int $cancelled_by
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class StockTransfer extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'stock_transfers';

    protected $fillable = [
        'tenant_id',
        'from_store_id',
        'to_store_id',
        'transfer_number',
        'ship_idempotency_key',
        'receive_idempotency_key',
        'status',
        'shipped_at',
        'received_at',
        'cancelled_at',
        'created_by',
        'shipped_by',
        'received_by',
        'cancelled_by',
        'notes',
    ];

    protected $casts = [
        'status' => StockTransferStatusEnum::class,
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Item detail baris transfer stok.
     *
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }

    /**
     * Cabang pengirim.
     *
     * @return BelongsTo<Store, $this>
     */
    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    /**
     * Cabang penerima.
     *
     * @return BelongsTo<Store, $this>
     */
    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    /**
     * Pengguna pembuat dokumen transfer.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Petugas pengirim transfer (ship).
     *
     * @return BelongsTo<User, $this>
     */
    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /**
     * Petugas penerima transfer (receive).
     *
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Petugas pembatal transfer.
     *
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Scope transfer yang melibatkan cabang tertentu (baik sebagai pengirim maupun penerima).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInvolvingStore(Builder $query, int $storeId): Builder
    {
        return $query->where(function (Builder $q) use ($storeId) {
            $q->where('from_store_id', $storeId)
                ->orWhere('to_store_id', $storeId);
        });
    }

    public function isDraft(): bool
    {
        return $this->status === StockTransferStatusEnum::Draft;
    }

    public function isInTransit(): bool
    {
        return $this->status === StockTransferStatusEnum::InTransit;
    }

    public function isReceived(): bool
    {
        return $this->status === StockTransferStatusEnum::Received;
    }

    public function isCancelled(): bool
    {
        return $this->status === StockTransferStatusEnum::Cancelled;
    }
}
