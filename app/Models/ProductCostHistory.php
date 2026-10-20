<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductCostHistory: Catatan riwayat biaya standar append-only per produk.
 */
class ProductCostHistory extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $table = 'product_cost_histories';

    protected $fillable = [
        'store_id',
        'product_id',
        'costing_method',
        'old_cost',
        'new_cost',
        'reason',
        'changed_by',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'old_cost' => 'decimal:2',
            'new_cost' => 'decimal:2',
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
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

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
