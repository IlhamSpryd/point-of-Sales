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
 * @property numeric-string $uom_conversion_factor
 * @property numeric-string $ordered_qty
 * @property numeric-string $base_ordered_qty
 * @property numeric-string $received_qty
 * @property numeric-string $accepted_qty
 * @property numeric-string $rejected_qty
 * @property numeric-string $returned_qty
 * @property numeric-string $invoiced_qty
 * @property numeric-string $unit_price
 * @property numeric-string $discount_pct
 * @property numeric-string $discount_amount
 * @property numeric-string $tax_rate
 * @property bool $tax_inclusive
 * @property numeric-string $tax_amount
 * @property numeric-string $line_total
 * @property numeric-string $over_receipt_tolerance_pct
 * @property string|null $notes
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Collection<int, PurchaseOrderLinePrAllocation> $prAllocations
 */
class PurchaseOrderLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_order_lines';

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'purchasable_type',
        'purchasable_id',
        'purchase_uom',
        'uom_conversion_factor',
        'ordered_qty',
        'base_ordered_qty',
        'received_qty',
        'accepted_qty',
        'rejected_qty',
        'returned_qty',
        'invoiced_qty',
        'unit_price',
        'discount_pct',
        'discount_amount',
        'tax_rate',
        'tax_inclusive',
        'tax_amount',
        'line_total',
        'over_receipt_tolerance_pct',
        'notes',
    ];

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

    /**
     * @return BelongsTo<Tenant, $this>
     */
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

    /**
     * @return MorphTo<Model, $this>
     */
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
     *
     * @return numeric-string
     */
    public function getRemainingReceivableQty(): string
    {
        $remaining = bcsub($this->ordered_qty, $this->accepted_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    /**
     * Kuantitas bersih efektif setelah retur:
     * Q_net_acc = Q_GRN_acc - Q_RET_post
     *
     * @return numeric-string
     */
    public function getNetAcceptedQty(): string
    {
        $net = bcsub($this->accepted_qty, $this->returned_qty, 4);

        return bccomp($net, '0', 4) > 0 ? $net : '0.0000';
    }

    /**
     * Sisa kuantitas yang masih dapat ditagihkan (3-Way Match):
     * Q_rem_inv = max(0, Q_net_acc - Q_INV_net)
     *
     * @return numeric-string
     */
    public function getRemainingInvoiceableQty(): string
    {
        $netAccepted = $this->getNetAcceptedQty();
        $remaining = bcsub($netAccepted, $this->invoiced_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    /**
     * Batas Maksimum Penerimaan dengan Toleransi Over-Receipt:
     * Q_max_receivable = max(0, Q_PO_ord * (1 + tau_over) - Q_GRN_acc)
     *
     * @return numeric-string
     */
    public function getMaxReceivableQty(): string
    {
        $toleranceRate = bcdiv($this->over_receipt_tolerance_pct, '100', 4);
        $multiplier = bcadd('1', $toleranceRate, 4);
        $maxAllowed = bcmul($this->ordered_qty, $multiplier, 4);
        $remaining = bcsub($maxAllowed, $this->accepted_qty, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }
}
