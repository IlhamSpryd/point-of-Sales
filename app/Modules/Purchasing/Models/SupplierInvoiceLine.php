<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $supplier_invoice_id
 * @property int|null $purchase_order_line_id
 * @property int $line_number
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property numeric-string $invoiced_qty
 * @property numeric-string $unit_price
 * @property numeric-string $discount_amount
 * @property numeric-string $tax_rate
 * @property numeric-string $tax_amount
 * @property numeric-string $line_total
 * @property string|null $notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Tenant $tenant
 * @property-read SupplierInvoice $supplierInvoice
 * @property-read PurchaseOrderLine|null $purchaseOrderLine
 * @property-read Model $purchasable
 * @property-read Collection<int, SupplierInvoiceGrnMatch> $grnMatches
 */
class SupplierInvoiceLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'supplier_invoice_lines';

    protected $fillable = [
        'tenant_id',
        'supplier_invoice_id',
        'purchase_order_line_id',
        'line_number',
        'purchasable_type',
        'purchasable_id',
        'invoiced_qty',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'invoiced_qty' => 'string',
            'unit_price' => 'string',
            'discount_amount' => 'string',
            'tax_rate' => 'string',
            'tax_amount' => 'string',
            'line_total' => 'string',
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
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<SupplierInvoiceGrnMatch, $this>
     */
    public function grnMatches(): HasMany
    {
        return $this->hasMany(SupplierInvoiceGrnMatch::class, 'supplier_invoice_line_id');
    }
}
