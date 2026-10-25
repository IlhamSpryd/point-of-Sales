<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $document_type
 * @property string $currency_code
 * @property string $min_amount
 * @property string|null $max_amount
 * @property int $step_order
 * @property int|null $approver_role_id
 * @property string|null $approver_permission
 * @property bool $is_active
 */
class PurchaseApprovalRule extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_approval_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'string',
            'max_amount' => 'string',
            'step_order' => 'integer',
            'is_active' => 'boolean',
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

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    /**
     * Mengevaluasi apakah aturan ini berlaku untuk nominal dokumen tertentu (inklusif).
     */
    public function matchesAmount(string|float|int $amount): bool
    {
        $amountStr = (string) $amount;

        // BaseAmount >= min_amount (inklusif)
        if (bccomp($amountStr, (string) $this->min_amount, 2) < 0) {
            return false;
        }

        // Jika max_amount ada, BaseAmount <= max_amount (inklusif)
        if ($this->max_amount !== null && bccomp($amountStr, (string) $this->max_amount, 2) > 0) {
            return false;
        }

        return true;
    }
}
