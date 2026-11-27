<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Modules\Organization\Models\Store;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared Eloquent relation helper to avoid cross-module boilerplate imports on Store.
 */
trait HasStoreRelation
{
    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
