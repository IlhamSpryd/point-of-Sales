<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductMedia: Multi-media file galeri produk (foto, thumbnail) terisolasi per tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $file_size_bytes
 * @property int $sort_order
 */
class ProductMedia extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $table = 'product_media';

    protected $fillable = [
        'product_id',
        'disk',
        'file_path',
        'mime_type',
        'file_size_bytes',
        'alt_text',
        'is_primary',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'file_size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
