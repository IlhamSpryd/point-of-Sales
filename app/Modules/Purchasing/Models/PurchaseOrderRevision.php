<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_order_id
 * @property int $revision_number
 * @property int $amended_by_user_id
 * @property CarbonInterface $amended_at
 * @property string $reason
 * @property array $snapshot_data
 * @property string $total_amount_before
 * @property string $total_amount_after
 * @property CarbonInterface $created_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read User $amendedByUser
 */
class PurchaseOrderRevision extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public $timestamps = false;

    protected $table = 'purchase_order_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'snapshot_data' => 'array',
            'total_amount_before' => 'string',
            'total_amount_after' => 'string',
            'amended_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function amendedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by_user_id');
    }
}
