<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_order_id
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property string $purchase_uom
 * @property string $uom_conversion_factor
 * @property string $ordered_qty
 * @property string $base_ordered_qty
 * @property string $received_qty
 * @property string $accepted_qty
 * @property string $rejected_qty
 * @property string $returned_qty
 * @property string $invoiced_qty
 * @property string $unit_price
 * @property string $discount_pct
 * @property string $discount_amount
 * @property string $tax_rate
 * @property bool $tax_inclusive
 * @property string $tax_amount
 * @property string $line_total
 * @property string $over_receipt_tolerance_pct
 * @property string|null $notes
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Collection<int, PurchaseOrderLinePrAllocation> $prAllocations
 */
class PurchaseOrderLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_order_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'uom_conversion_factor' => 'string',
            'ordered_qty' => 'string',
            'base_ordered_qty' => 'string',
            'received_qty' => 'string',
            'accepted_qty' => 'string',
            'rejected_qty' => 'string',
            'returned_qty' => 'string',
            'invoiced_qty' => 'string',
            'unit_price' => 'string',
            'discount_pct' => 'string',
            'discount_amount' => 'string',
            'tax_rate' => 'string',
            'tax_amount' => 'string',
            'line_total' => 'string',
            'over_receipt_tolerance_pct' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PurchaseOrderLinePrAllocation, $this>
     */
    public function prAllocations(): HasMany
    {
        return $this->hasMany(PurchaseOrderLinePrAllocation::class, 'purchase_order_line_id');
    }

    /**
     * @return HasMany<GoodsReceivedNoteLine, $this>
     */
    public function grnLines(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteLine::class, 'purchase_order_line_id');
    }

    /**
     * Sisa kuantitas PO yang masih terbuka untuk diterima:
     * Q_PO_rem_rec = max(0, Q_PO_ord - Q_GRN_acc)
     */
    public function getRemainingReceivableQty(): string
    {
        $remaining = bcsub((string) $this->ordered_qty, (string) $this->accepted_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    /**
     * Kuantitas bersih efektif setelah retur:
     * Q_net_acc = Q_GRN_acc - Q_RET_post
     */
    public function getNetAcceptedQty(): string
    {
        $net = bcsub((string) $this->accepted_qty, (string) $this->returned_qty, 4);

        return bccomp($net, '0', 4) > 0 ? $net : '0.0000';
    }

    /**
     * Sisa kuantitas yang masih dapat ditagihkan (3-Way Match):
     * Q_rem_inv = max(0, Q_net_acc - Q_INV_net)
     */
    public function getRemainingInvoiceableQty(): string
    {
        $netAccepted = $this->getNetAcceptedQty();
        $remaining = bcsub($netAccepted, (string) $this->invoiced_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    /**
     * Batas Maksimum Penerimaan dengan Toleransi Over-Receipt:
     * Q_max_receivable = max(0, Q_PO_ord * (1 + tau_over) - Q_GRN_acc)
     */
    public function getMaxReceivableQty(): string
    {
        $toleranceRate = bcdiv((string) $this->over_receipt_tolerance_pct, '100', 4);
        $multiplier = bcadd('1', $toleranceRate, 4);
        $maxAllowed = bcmul((string) $this->ordered_qty, $multiplier, 4);
        $remaining = bcsub($maxAllowed, (string) $this->accepted_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }
}
