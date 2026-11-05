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
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $ordered_qty
 * @property numeric-string $reserved_qty
 * @property numeric-string $fulfilled_qty
 * @property numeric-string $cancelled_qty
 * @property numeric-string $invoiced_qty
 * @property numeric-string $returned_qty
 * @property numeric-string $base_unit_price
 * @property numeric-string $unit_price
 * @property numeric-string $discount_amount
 * @property numeric-string $tax_rate
 * @property numeric-string $tax_amount
 * @property numeric-string $line_total
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_order_id
 * @property int $line_number
 * @property int $product_id
 * @property int|null $variant_id
 * @property int|null $uom_id
 * @property int|null $price_list_id
 */
class SalesOrderLine extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
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

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
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
