<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global scope: batasi query ke store aktif (branch) dari TenantContext.
 */
trait ScopedToStore
{
    public static function bootScopedToStore(): void
    {
        static::addGlobalScope(new StoreScope);
    }

    /**
     * Mode baca lintas-cabang. HANYA boleh dipakai di jalur yang sudah
     * memverifikasi peran Owner (laporan).
     */
    public static function allStores(): Builder
    {
        return static::withoutGlobalScope(StoreScope::class);
    }
}
