<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\StockOpnameDriftResolutionEnum;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model Baris Detail Penghitungan Fisik Stok (Stock Opname Item).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $stock_opname_id
 * @property string $item_type // 'product' | 'ingredient'
 * @property ?int $product_id
 * @property ?int $ingredient_id
 * @property string $snapshot_on_hand
 * @property ?int $snapshot_last_movement_id
 * @property ?string $counted_quantity
 * @property ?string $variance_quantity
 * @property StockOpnameDriftResolutionEnum $drift_resolution_mode
 * @property ?string $reason_code
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class StockOpnameItem extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'stock_opname_items';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'stock_opname_id',
        'item_type',
        'product_id',
        'ingredient_id',
        'snapshot_on_hand',
        'snapshot_last_movement_id',
        'counted_quantity',
        'variance_quantity',
        'drift_resolution_mode',
        'reason_code',
        'notes',
    ];

    protected $casts = [
        'snapshot_on_hand' => 'decimal:4',
        'snapshot_last_movement_id' => 'integer',
        'counted_quantity' => 'decimal:4',
        'variance_quantity' => 'decimal:4',
        'drift_resolution_mode' => StockOpnameDriftResolutionEnum::class,
    ];

    /**
     * Dokumen sesi opname induk.
     *
     * @return BelongsTo<StockOpname, $this>
     */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
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
}
