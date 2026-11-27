<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global scope: batasi query ke store aktif (branch) dari TenantContext (ADR-113).
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
     *
     * @return Builder<static>
     */
    public static function allStores(): Builder
    {
        // @tenant-bypass: auth_lookup
        return static::query()->withoutGlobalScope(StoreScope::class);
    }

    /**
     * Explicitly allows querying all branches within the active tenant without bypassing tenant_id.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAllBranchesInTenant(Builder $query): Builder
    {
        // @tenant-bypass: auth_lookup
        return $query->withoutGlobalScope(StoreScope::class);
    }

    /**
     * Explicitly scopes query to a specified branch within the active tenant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $storeId): Builder
    {
        // @tenant-bypass: auth_lookup
        return $query->withoutGlobalScope(StoreScope::class)->where($this->qualifyColumn('store_id'), $storeId);
    }
}
