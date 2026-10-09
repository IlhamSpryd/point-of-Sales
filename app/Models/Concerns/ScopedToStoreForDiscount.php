<?php

namespace App\Models\Concerns;

use App\Models\Scopes\DiscountStoreScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Discount: store_id NULL berarti berlaku tenant-wide; bila terisi,
 * hanya berlaku untuk cabang tersebut. Mode allStores() hanya untuk Owner.
 */
trait ScopedToStoreForDiscount
{
    public static function bootScopedToStoreForDiscount(): void
    {
        static::addGlobalScope(new DiscountStoreScope);
    }

    public static function allStores(): Builder
    {
        return static::withoutGlobalScope(DiscountStoreScope::class);
    }
}
