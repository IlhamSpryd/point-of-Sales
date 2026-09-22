<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(CustomerLoyaltyAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyLedger::class);
    }
}
