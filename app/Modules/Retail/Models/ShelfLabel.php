<?php

declare(strict_types=1);

namespace App\Modules\Retail\Models;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShelfLabel extends Model
{
    public const STATUS_PENDING = 'SHELF_LABEL_PENDING_PRINT';
    public const STATUS_PRINTED = 'PRINTED';

    protected $table = 'shelf_labels';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'product_id',
        'product_variant_id',
        'old_price',
        'new_price',
        'status',
        'batch_number',
        'printed_at',
        'printed_by_user_id',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'printed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by_user_id');
    }
}
