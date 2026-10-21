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
 * Model Baris Detail Retur Barang ke Supplier (Supplier Return Item).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_return_id
 * @property string $item_type // 'product' | 'ingredient'
 * @property ?int $product_id
 * @property ?int $ingredient_id
 * @property string $quantity
 * @property string $unit_cost
 * @property ?string $reason
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SupplierReturnItem extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'supplier_return_items';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'supplier_return_id',
        'item_type',
        'product_id',
        'ingredient_id',
        'quantity',
        'unit_cost',
        'reason',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    /**
     * Dokumen retur supplier induk.
     *
     * @return BelongsTo<SupplierReturn, $this>
     */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class, 'supplier_return_id');
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
     * Cabang / Toko pemilik saldo barang.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Ambil nama representatif dari item (produk atau bahan baku).
     */
    public function getItemName(): string
    {
        if ($this->item_type === 'product' && $this->product) {
            return $this->product->product_name ?? 'Produk #'.$this->product_id;
        }

        if ($this->item_type === 'ingredient' && $this->ingredient) {
            return $this->ingredient->name ?? 'Bahan Baku #'.$this->ingredient_id;
        }

        return ucfirst($this->item_type).' #'.($this->product_id ?? $this->ingredient_id);
    }
}
