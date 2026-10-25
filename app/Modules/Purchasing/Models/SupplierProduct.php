<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierProduct extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'supplier_products';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'uom_conversion_factor' => 'string',
            'standard_price' => 'string',
            'last_purchase_price' => 'string',
            'lead_time_days' => 'integer',
            'min_order_qty' => 'string',
            'order_multiple_qty' => 'string',
            'is_preferred' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Konversi kuantitas dari purchase UoM ke base stock UoM menggunakan BCMath.
     */
    public function calculateBaseQty(string|int|float $purchaseQty): string
    {
        return bcmul((string) $purchaseQty, (string) $this->uom_conversion_factor, 4);
    }

    /**
     * Konversi kuantitas dari base stock UoM ke purchase UoM menggunakan BCMath.
     */
    public function calculatePurchaseQty(string|int|float $baseQty): string
    {
        if (bccomp((string) $this->uom_conversion_factor, '0', 6) <= 0) {
            throw new \InvalidArgumentException('uom_conversion_factor must be greater than zero.');
        }

        return bcdiv((string) $baseQty, (string) $this->uom_conversion_factor, 4);
    }
}
