<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\ApprovalStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int|null $rule_id
 * @property int $step_order
 * @property ApprovalStatusEnum $status
 * @property int $submitted_by_user_id
 * @property int|null $approved_by_user_id
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $decided_at
 * @property-read PurchaseApprovalRule|null $rule
 * @property-read Collection<int, PurchaseApprovalLog> $logs
 */
class PurchaseApprovalRequest extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_approval_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatusEnum::class,
            'step_order' => 'integer',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<PurchaseApprovalRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PurchaseApprovalRule::class, 'rule_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<PurchaseApprovalLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PurchaseApprovalLog::class, 'approval_request_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatusEnum::Pending;
    }
}
