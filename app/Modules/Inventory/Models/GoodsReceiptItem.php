<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model Baris Detail Penerimaan Barang (Goods Receipt Item).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $goods_receipt_id
 * @property string $item_type // 'product' | 'ingredient'
 * @property ?int $product_id
 * @property ?int $ingredient_id
 * @property string $quantity_received
 * @property string $unit_cost
 * @property ?string $batch_number // OD-02 Lot Receipt Annotation
 * @property ?Carbon $expiry_date // OD-02 Lot Receipt Annotation
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class GoodsReceiptItem extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'goods_receipt_items';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'goods_receipt_id',
        'item_type',
        'product_id',
        'ingredient_id',
        'quantity_received',
        'unit_cost',
        'batch_number',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    /**
     * Dokumen penerimaan induk.
     *
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
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
     * Mendapatkan nama item secara polimorfik.
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
     * Menghitung subtotal nilai inbound baris ini.
     */
    public function getSubtotalAttribute(): string
    {
        return bcmul((string) $this->quantity_received, (string) $this->unit_cost, 4);
    }
}
