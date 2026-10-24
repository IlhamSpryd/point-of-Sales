<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\PoInvoicingStatusEnum;
use App\Enums\PoReceivingStatusEnum;
use App\Enums\PurchaseOrderSourceTypeEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property string $po_number
 * @property int $revision_number
 * @property PurchaseOrderSourceTypeEnum $source_type
 * @property CarbonInterface $order_date
 * @property CarbonInterface|null $expected_delivery_date
 * @property int $payment_terms_days
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $subtotal_amount
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $additional_cost_estimate
 * @property string $total_amount
 * @property PurchaseOrderStatusEnum $status
 * @property PoReceivingStatusEnum $receiving_status
 * @property PoInvoicingStatusEnum $invoicing_status
 * @property int $created_by_user_id
 * @property int|null $approved_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property int|null $released_by_user_id
 * @property CarbonInterface|null $released_at
 * @property string $idempotency_key
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property-read Supplier $supplier
 * @property-read Collection<int, PurchaseOrderLine> $lines
 * @property-read Collection<int, PurchaseOrderRevision> $revisions
 * @property-read Collection<int, PurchaseApprovalRequest> $approvalRequests
 */
class PurchaseOrder extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'purchase_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'payment_terms_days' => 'integer',
            'source_type' => PurchaseOrderSourceTypeEnum::class,
            'status' => PurchaseOrderStatusEnum::class,
            'receiving_status' => PoReceivingStatusEnum::class,
            'invoicing_status' => PoInvoicingStatusEnum::class,
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'exchange_rate' => 'string',
            'subtotal_amount' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'additional_cost_estimate' => 'string',
            'total_amount' => 'string',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
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

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseOrderRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PurchaseOrderRevision::class, 'purchase_order_id')->orderBy('revision_number', 'asc');
    }

    /**
     * @return HasMany<GoodsReceivedNote, $this>
     */
    public function goodsReceivedNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivedNote::class, 'purchase_order_id')->orderBy('id', 'asc');
    }

    /**
     * @return MorphMany<PurchaseApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(PurchaseApprovalRequest::class, 'approvable');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Draft;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::PendingApproval;
    }

    public function isApproved(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Approved;
    }

    public function isReleased(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Released;
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Cancelled;
    }

    public function isClosed(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Closed;
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [PurchaseOrderStatusEnum::Draft, PurchaseOrderStatusEnum::PendingApproval], true);
    }

    public function canBeReleased(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Approved;
    }

    public function canBeAmended(): bool
    {
        return $this->status === PurchaseOrderStatusEnum::Released;
    }

    public function canBeCancelled(): bool
    {
        if ($this->isCancelled() || $this->isClosed()) {
            return false;
        }

        // Dilarang membatalkan jika sudah ada penerimaan fisik atau penagihan
        foreach ($this->lines as $line) {
            if (bccomp((string) $line->received_qty, '0', 4) > 0 || bccomp((string) $line->invoiced_qty, '0', 4) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Route binding di-scope ke tenant pada konteks aktif (404 bila lintas tenant).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first();
        }

        if ($context->hasTenant()) {
            return static::query()
                ->where('tenant_id', $context->requireTenantId())
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first();
        }

        return null;
    }
}
