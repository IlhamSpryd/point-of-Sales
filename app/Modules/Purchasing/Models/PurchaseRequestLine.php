<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_request_id
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property string $requested_qty
 * @property string $approved_qty
 * @property string $converted_to_po_qty
 * @property string $uom
 * @property string $estimated_unit_price
 * @property string $estimated_subtotal
 * @property int|null $preferred_supplier_id
 * @property string|null $notes
 * @property-read PurchaseRequest $purchaseRequest
 * @property-read Collection<int, PurchaseOrderLinePrAllocation> $poAllocations
 */
class PurchaseRequestLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_request_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'string',
            'approved_qty' => 'string',
            'converted_to_po_qty' => 'string',
            'estimated_unit_price' => 'string',
            'estimated_subtotal' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    /**
     * @return HasMany<PurchaseOrderLinePrAllocation, $this>
     */
    public function poAllocations(): HasMany
    {
        return $this->hasMany(PurchaseOrderLinePrAllocation::class, 'purchase_request_line_id');
    }

    /**
     * Hitung sisa kuantitas yang dapat dikonversi ke PO.
     */
    public function remainingOrderableQty(): string
    {
        $remaining = bcsub((string) $this->approved_qty, (string) $this->converted_to_po_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }
}
