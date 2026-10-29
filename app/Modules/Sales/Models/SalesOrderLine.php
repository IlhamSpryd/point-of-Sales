<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'line_number',
        'product_id',
        'variant_id',
        'uom_id',
        'description',
        'ordered_qty',
        'reserved_qty',
        'fulfilled_qty',
        'cancelled_qty',
        'invoiced_qty',
        'returned_qty',
        'base_unit_price',
        'unit_price',
        'price_list_id',
        'pricing_source',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'ordered_qty' => 'string',
            'reserved_qty' => 'string',
            'fulfilled_qty' => 'string',
            'cancelled_qty' => 'string',
            'invoiced_qty' => 'string',
            'returned_qty' => 'string',
            'base_unit_price' => 'string',
            'unit_price' => 'string',
            'discount_amount' => 'string',
            'tax_rate' => 'string',
            'tax_amount' => 'string',
            'line_total' => 'string',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    /**
     * Kuantitas yang belum terpenuhi dan masih aktif (ordered_qty - fulfilled_qty - cancelled_qty).
     */
    public function getRemainingQty(): string
    {
        $ordered = (string) $this->ordered_qty;
        $fulfilled = (string) $this->fulfilled_qty;
        $cancelled = (string) $this->cancelled_qty;

        $sub = bcsub($ordered, $fulfilled, 4);
        $rem = bcsub($sub, $cancelled, 4);

        return bccomp($rem, '0', 4) > 0 ? $rem : '0.0000';
    }
}
