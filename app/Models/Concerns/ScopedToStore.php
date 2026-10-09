<?php

namespace App\Models\Concerns;

use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global scope: batasi query ke store aktif (branch) dari TenantContext.
 *
 * Karena ini boundary isolasi cabang yang sesungguhnya, bypass HARUS eksplisit
 * dan hanya untuk Owner pada mode laporan lintas-cabang (allStores()).
 */
trait ScopedToStore
{
    public static function bootScopedToStore(): void
    {
        static::addGlobalScope(new StoreScope);
    }

    /**
     * Mode baca lintas-cabang. HANYA boleh dipakai di jalur yang sudah
     * memverifikasi peran Owner (laporan). Setiap pemakaian wajib tercatat
     * di laporan Phase 11.
     */
    public static function allStores(): Builder
    {
        return static::withoutGlobalScope(StoreScope::class);
    }
}
