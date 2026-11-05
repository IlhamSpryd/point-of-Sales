<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $default_discount_rate
 * @property int $id
 * @property int $tenant_id
 * @property int|null $default_price_list_id
 */
class CustomerGroup extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'default_discount_rate' => 'decimal:2',
            'is_b2b_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_group_id');
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function defaultPriceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'default_price_list_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
