<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\StockOpnameStatusEnum;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model Dokumen Sesi Penghitungan Fisik Stok (Stock Opname).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $opname_number
 * @property string $idempotency_key
 * @property StockOpnameStatusEnum $status
 * @property ?Carbon $snapshot_taken_at
 * @property ?Carbon $submitted_at
 * @property ?Carbon $approved_at
 * @property int $created_by
 * @property ?int $submitted_by
 * @property ?int $approved_by
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class StockOpname extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'stock_opnames';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'opname_number',
        'idempotency_key',
        'status',
        'snapshot_taken_at',
        'submitted_at',
        'approved_at',
        'created_by',
        'submitted_by',
        'approved_by',
        'notes',
    ];

    protected $casts = [
        'status' => StockOpnameStatusEnum::class,
        'snapshot_taken_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /**
     * Item detail baris opname.
     *
     * @return HasMany<StockOpnameItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'stock_opname_id');
    }

    /**
     * Cabang / Toko tempat opname dilakukan.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Pengguna pembuat dokumen sesi opname.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Petugas penghitung yang mensubmit hasil hitung opname.
     *
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Manajer / Pengesah yang menyetujui hasil rekonsiliasi opname.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === StockOpnameStatusEnum::Draft;
    }

    public function isCounting(): bool
    {
        return $this->status === StockOpnameStatusEnum::Counting;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === StockOpnameStatusEnum::PendingApproval;
    }

    public function isApproved(): bool
    {
        return $this->status === StockOpnameStatusEnum::Approved;
    }

    public function isCancelled(): bool
    {
        return $this->status === StockOpnameStatusEnum::Cancelled;
    }
}
