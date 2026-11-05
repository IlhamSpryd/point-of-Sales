<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Models\PlanEntitlement;
use App\Models\TenantSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $price
 * @property int $id
 */
class Plan extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'price',
        'billing_cycle',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /**
     * @return HasMany<TenantSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}
