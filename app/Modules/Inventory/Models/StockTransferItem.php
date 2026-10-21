<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Enums\StockTransferDiscrepancyResolutionEnum;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model Baris Detail Transfer Stok Antarcabang (Stock Transfer Item).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $stock_transfer_id
 * @property string $item_type // 'product' | 'ingredient'
 * @property ?int $product_id
 * @property ?int $ingredient_id
 * @property string $quantity_requested
 * @property string $quantity_shipped
 * @property ?string $quantity_received
 * @property string $discrepancy_quantity
 * @property StockTransferDiscrepancyResolutionEnum $discrepancy_resolution
 * @property ?string $discrepancy_reason_code
 * @property string $carrying_unit_cost
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class StockTransferItem extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'stock_transfer_items';

    protected $fillable = [
        'tenant_id',
        'stock_transfer_id',
        'item_type',
        'product_id',
        'ingredient_id',
        'quantity_requested',
        'quantity_shipped',
        'quantity_received',
        'discrepancy_quantity',
        'discrepancy_resolution',
        'discrepancy_reason_code',
        'carrying_unit_cost',
        'notes',
    ];

    protected $casts = [
        'quantity_requested' => 'decimal:4',
        'quantity_shipped' => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'discrepancy_quantity' => 'decimal:4',
        'carrying_unit_cost' => 'decimal:4',
        'discrepancy_resolution' => StockTransferDiscrepancyResolutionEnum::class,
    ];

    /**
     * Dokumen transfer induk.
     *
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
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
}
