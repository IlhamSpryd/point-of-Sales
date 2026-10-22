<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\PurchaseRequestFulfillmentStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
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
 * @property string $pr_number
 * @property int $requested_by_user_id
 * @property CarbonInterface $required_by_date
 * @property PurchaseRequestStatusEnum $status
 * @property PurchaseRequestFulfillmentStatusEnum $fulfillment_status
 * @property string $estimated_total_amount
 * @property string $currency_code
 * @property string|null $notes
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $approved_at
 * @property int|null $approved_by_user_id
 * @property-read Collection<int, PurchaseRequestLine> $lines
 * @property-read Collection<int, PurchaseApprovalRequest> $approvalRequests
 */
class PurchaseRequest extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'purchase_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'required_by_date' => 'date',
            'status' => PurchaseRequestStatusEnum::class,
            'fulfillment_status' => PurchaseRequestFulfillmentStatusEnum::class,
            'estimated_total_amount' => 'string',
            'approved_at' => 'datetime',
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

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<PurchaseRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'purchase_request_id');
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
        return $this->status === PurchaseRequestStatusEnum::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === PurchaseRequestStatusEnum::Submitted;
    }

    public function isApproved(): bool
    {
        return $this->status === PurchaseRequestStatusEnum::Approved;
    }

    public function isImmutable(): bool
    {
        return $this->status !== PurchaseRequestStatusEnum::Draft;
    }

    public function canBeEdited(): bool
    {
        return $this->status === PurchaseRequestStatusEnum::Draft;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [PurchaseRequestStatusEnum::Draft, PurchaseRequestStatusEnum::Submitted], true);
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
