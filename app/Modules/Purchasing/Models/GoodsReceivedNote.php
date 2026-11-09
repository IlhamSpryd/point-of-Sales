<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\GrnStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int $purchase_order_id
 * @property string $grn_number
 * @property string|null $supplier_delivery_note_no
 * @property GrnStatusEnum $status
 * @property CarbonInterface $received_at
 * @property int $received_by_user_id
 * @property CarbonInterface|null $posted_at
 * @property int|null $posted_by_user_id
 * @property CarbonInterface|null $reversed_at
 * @property int|null $reversed_by_user_id
 * @property string|null $reversal_reason
 * @property int|null $reversal_of_grn_id
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Tenant $tenant
 * @property-read Store $store
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read User $receivedBy
 * @property-read User|null $postedBy
 * @property-read User|null $reversedBy
 * @property-read GoodsReceivedNote|null $reversalOf
 * @property-read Collection<int, GoodsReceivedNoteLine> $lines
 */
class GoodsReceivedNote extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'goods_received_notes';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'supplier_id',
        'purchase_order_id',
        'grn_number',
        'supplier_delivery_note_no',
        'status',
        'received_at',
        'received_by_user_id',
        'posted_at',
        'posted_by_user_id',
        'reversed_at',
        'reversed_by_user_id',
        'reversal_reason',
        'reversal_of_grn_id',
        'notes',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrnStatusEnum::class,
            'received_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    /**
     * @return BelongsTo<GoodsReceivedNote, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_grn_id');
    }

    /**
     * @return HasMany<GoodsReceivedNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteLine::class, 'goods_received_note_id')->orderBy('line_number', 'asc');
    }

    public function isDraft(): bool
    {
        return $this->status === GrnStatusEnum::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === GrnStatusEnum::Posted;
    }

    public function isReversed(): bool
    {
        return $this->status === GrnStatusEnum::Reversed;
    }

    public function isCancelled(): bool
    {
        return $this->status === GrnStatusEnum::Cancelled;
    }
}
