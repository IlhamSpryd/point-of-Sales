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

    protected $guarded = [];

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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
