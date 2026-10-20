<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\ProductAttribute;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ProductAttributeValue mewakili opsi nilai dari atribut produk (misal: "Small", "Medium", "Large").
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $attribute_id
 * @property string $value
 * @property string|null $code
 * @property int $sort_order
 * @property bool $is_active
 */
class ProductAttributeValue extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'product_attribute_values';

    protected $fillable = [
        'tenant_id',
        'attribute_id',
        'value',
        'code',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'attribute_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }
}
