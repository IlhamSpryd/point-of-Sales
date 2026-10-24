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
 * @property int $supplier_invoice_id
 * @property int $supplier_invoice_line_id
 * @property int $goods_received_note_line_id
 * @property string $matched_qty
 * @property string $po_unit_price
 * @property string $invoice_unit_price
 * @property string $qty_variance
 * @property string $price_variance_amount
 * @property string $tax_variance_amount
 * @property string $variance_status
 * @property bool $is_variance_approved
 * @property int|null $variance_approved_by_user_id
 * @property string|null $variance_approval_notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Tenant $tenant
 * @property-read SupplierInvoice $supplierInvoice
 * @property-read SupplierInvoiceLine $supplierInvoiceLine
 * @property-read GoodsReceivedNoteLine $goodsReceivedNoteLine
 * @property-read User|null $varianceApprovedBy
 */
class SupplierInvoiceGrnMatch extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'supplier_invoice_grn_matches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'matched_qty' => 'string',
            'po_unit_price' => 'string',
            'invoice_unit_price' => 'string',
            'qty_variance' => 'string',
            'price_variance_amount' => 'string',
            'tax_variance_amount' => 'string',
            'is_variance_approved' => 'boolean',
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
     * @return BelongsTo<SupplierInvoiceLine, $this>
     */
    public function supplierInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoiceLine::class, 'supplier_invoice_line_id');
    }

    /**
     * @return BelongsTo<GoodsReceivedNoteLine, $this>
     */
    public function goodsReceivedNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteLine::class, 'goods_received_note_line_id');
    }

    public function varianceApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'variance_approved_by_user_id');
    }
}
