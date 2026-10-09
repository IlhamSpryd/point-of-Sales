<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStoreForDiscount;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use AssignsTenant;
    use HasFactory, SoftDeletes;
    use ScopedToStoreForDiscount;
    use ScopedToTenant;

    protected $fillable = [
        'code', 'name', 'type', 'value', 'max_discount_amount',
        'min_purchase_amount', 'is_active', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    /** Relasi (HasMany): Satu master diskon bisa dipakai oleh banyak transaksi. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
