<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Trait: pasang TenantScope sebagai global scope (scope global "tenant").
 * Catatan: User TIDAK memakai trait ini — auth provider harus bisa
 * menemukan user hanya dari email sebelum konteks tenant diketahui (BD-7).
 * User di-scope secara eksplisit di UserService / route binding.
 */
trait ScopedToTenant
{
    protected static function bootScopedToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Escape hatch eksplisit & tervalog: jalankan query tanpa filter tenant.
     * Gunakan hanya untuk operasi sistem (seeder/backfill/login), tidak pernah untuk request web.
     */
    public static function withoutTenantScope(): Builder
    {
        Log::warning('Bypassing tenant scope.', [
            'model' => static::class,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);

        return static::withoutGlobalScope(TenantScope::class);
    }
}
