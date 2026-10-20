<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot model pengait antara variant dengan attribute dan attribute_value.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $variant_id
 * @property int $attribute_id
 * @property int $attribute_value_id
 */
class ProductVariantAttributeValue extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'product_variant_attribute_values';

    protected $fillable = [
        'tenant_id',
        'variant_id',
        'attribute_id',
        'attribute_value_id',
    ];

    protected $casts = [
        'variant_id' => 'integer',
        'attribute_id' => 'integer',
        'attribute_value_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'attribute_value_id');
    }
}
