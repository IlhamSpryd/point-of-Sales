<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_order_line_id
 * @property int $purchase_request_line_id
 * @property string $allocated_qty
 * @property CarbonInterface $created_at
 * @property-read PurchaseOrderLine $purchaseOrderLine
 * @property-read PurchaseRequestLine $purchaseRequestLine
 */
class PurchaseOrderLinePrAllocation extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public $timestamps = false;

    protected $table = 'purchase_order_line_pr_allocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allocated_qty' => 'string',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /**
     * @return BelongsTo<PurchaseRequestLine, $this>
     */
    public function purchaseRequestLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestLine::class, 'purchase_request_line_id');
    }
}
