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

    protected $guarded = ['id'];

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

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

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

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
