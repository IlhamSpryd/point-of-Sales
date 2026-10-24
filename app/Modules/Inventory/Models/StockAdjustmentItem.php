<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model Baris Detail Penyesuaian Stok (Stock Adjustment Item).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $stock_adjustment_id
 * @property string $item_type // 'product' | 'ingredient'
 * @property ?int $product_id
 * @property ?int $ingredient_id
 * @property string $direction // 'IN' | 'OUT'
 * @property string $quantity
 * @property string $unit_cost_snapshot
 * @property ?string $reason_note
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class StockAdjustmentItem extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'stock_adjustment_items';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'stock_adjustment_id',
        'item_type',
        'product_id',
        'ingredient_id',
        'direction',
        'quantity',
        'unit_cost_snapshot',
        'reason_note',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost_snapshot' => 'decimal:4',
    ];

    /**
     * Dokumen penyesuaian induk.
     *
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /**
     * Relasi ke Product jika item_type = 'product'.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relasi ke Ingredient jika item_type = 'ingredient'.
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

    /**
     * Cabang / Toko.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Mendapatkan nama item secara polimorfik dan type-safe.
     */
    public function getItemNameAttribute(): string
    {
        if ($this->item_type === 'product') {
            $product = $this->product;
            if ($product !== null) {
                return $product->product_name ?: ($product->name ?? "Product #{$this->product_id}");
            }

            return "Product #{$this->product_id}";
        }

        $ingredient = $this->ingredient;
        if ($ingredient !== null) {
            return $ingredient->name ?: "Ingredient #{$this->ingredient_id}";
        }

        return "Ingredient #{$this->ingredient_id}";
    }

    /**
     * Menghitung subtotal nilai baris penyesuaian ini.
     */
    public function getSubtotalAttribute(): string
    {
        return bcmul((string) $this->quantity, (string) $this->unit_cost_snapshot, 4);
    }
}
