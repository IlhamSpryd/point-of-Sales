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
 * @property string $invoiced_qty
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $line_total
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

    protected $guarded = ['id'];

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
