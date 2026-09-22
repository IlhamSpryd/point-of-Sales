<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'cost_per_unit' => 'decimal:4',
        'current_stock' => 'decimal:4',
        'reorder_level' => 'decimal:4',
    ];

    /**
     * [OMEGA-NODE1] FIX PRASYARAT BOM: Hapus 'unit_of_measurement' dari pivot.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_ingredients')
            ->withPivot('quantity_required');
    }

    public static function lockAndGetIngredient(int $id): ?self
    {
        return self::where('id', $id)->lockForUpdate()->first();
    }
}
