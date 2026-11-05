<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [D2-BE-005] Saldo stok otoritatif per (tenant, store, product):
 * product_stock_balances. Primary key komposit (tanpa kolom id),
 * sehingga Operasi tulis selalu harus menyertakan tenant_id & store_id.
 *
 * @property numeric-string $quantity
 * @property numeric-string $reserved_quantity
 * @property numeric-string $baseline_quantity
 * @property numeric-string $min_stock_level
 * @property numeric-string $reorder_point
 * @property numeric-string|null $max_stock_level
 * @property numeric-string $average_unit_cost
 * @property numeric-string $last_unit_cost
 * @property int $tenant_id
 * @property int $store_id
 * @property int $product_id
 * @property int $baseline_movement_id
 * @property int|null $last_movement_id
 */
class StockBalance extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public $incrementing = false;

    protected $table = 'product_stock_balances';

    protected $primaryKey = null;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'product_id',
        'quantity',
        'reserved_quantity',
        'baseline_quantity',
        'baseline_movement_id',
        'min_stock_level',
        'reorder_point',
        'max_stock_level',
        'average_unit_cost',
        'last_unit_cost',
        'last_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'baseline_quantity' => 'decimal:4',
            'baseline_movement_id' => 'integer',
            'min_stock_level' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'max_stock_level' => 'decimal:4',
            'average_unit_cost' => 'decimal:4',
            'last_unit_cost' => 'decimal:4',
            'last_movement_id' => 'integer',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
