<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerAddress extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default_billing' => 'boolean',
            'is_default_shipping' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->province,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    public function scopeBilling(Builder $query): Builder
    {
        return $query->whereIn('address_type', ['BILLING', 'BOTH']);
    }

    public function scopeShipping(Builder $query): Builder
    {
        return $query->whereIn('address_type', ['SHIPPING', 'BOTH']);
    }
}
