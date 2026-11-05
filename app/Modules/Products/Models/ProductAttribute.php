<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\ProductAttributeValue;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model ProductAttribute mewakili definisi atribut produk (misal: "Ukuran", "Warna").
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $code
 * @property int $sort_order
 * @property bool $is_active
 */
class ProductAttribute extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'product_attributes';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'attribute_id')->orderBy('sort_order', 'asc');
    }
}
