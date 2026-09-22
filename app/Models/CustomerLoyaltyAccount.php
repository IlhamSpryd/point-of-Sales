<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyaltyAccount extends Model
{
    protected $guarded = ['id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currentTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'current_tier_id');
    }

    public static function lockAndGetAccount(int $customerId): ?self
    {
        return self::where('customer_id', $customerId)->lockForUpdate()->first();
    }
}
