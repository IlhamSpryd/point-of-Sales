<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'quotation_id',
        'line_number',
        'product_id',
        'variant_id',
        'uom_id',
        'description',
        'quantity',
        'base_unit_price',
        'unit_price',
        'price_list_id',
        'pricing_source',
        'discount_amount',
        'tax_class_id',
        'tax_rate',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'string',
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
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

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class, 'tax_class_id');
    }
}
