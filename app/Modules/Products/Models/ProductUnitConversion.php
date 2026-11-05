<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductUnitConversion: Aturan konversi satuan alternatif per produk ke satuan dasar.
 *
 * @property numeric-string $conversion_factor
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $from_uom_id
 * @property int $to_uom_id
 */
class ProductUnitConversion extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'from_uom_id',
        'to_uom_id',
        'conversion_factor',
        'multiplier',
        'barcode',
    ];

    public function getMultiplierAttribute(): ?string
    {
        return isset($this->attributes['conversion_factor']) ? (string) $this->attributes['conversion_factor'] : null;
    }

    public function setMultiplierAttribute(mixed $value): void
    {
        $this->attributes['conversion_factor'] = $value;
    }

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function fromUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'from_uom_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function toUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'to_uom_id');
    }
}
