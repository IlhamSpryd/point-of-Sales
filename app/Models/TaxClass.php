<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TaxClass: Master klasifikasi dan tarif persentase pajak produk per tenant.
 */
class TaxClass extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'tax_classes';

    protected $fillable = [
        'code',
        'name',
        'rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'tax_class_id');
    }
}
