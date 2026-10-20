<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UnitOfMeasure: Satuan ukur standar (PCS, KG, L, PACK, dll) terisolasi per tenant.
 */
class UnitOfMeasure extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'code',
        'name',
        'precision_scale',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'precision_scale' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'base_uom_id');
    }

    public function conversionsFrom(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class, 'from_uom_id');
    }

    public function conversionsTo(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class, 'to_uom_id');
    }
}
