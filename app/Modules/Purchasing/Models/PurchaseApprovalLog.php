<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $approval_request_id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property string $action
 * @property int $actor_id
 * @property int|null $actor_role_id
 * @property int $step_order
 * @property string|null $reason
 * @property array<string, mixed>|null $payload_snapshot
 * @property CarbonInterface $created_at
 */
class PurchaseApprovalLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $table = 'purchase_approval_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'payload_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseApprovalRequest::class, 'approval_request_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'actor_role_id');
    }
}
