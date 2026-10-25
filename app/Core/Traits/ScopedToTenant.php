<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Trait: pasang TenantScope sebagai global scope (scope global "tenant").
 */
trait ScopedToTenant
{
    protected static function bootScopedToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Escape hatch eksplisit & tervalog: jalankan query tanpa filter tenant.
     *
     * @return Builder<static>
     */
    public static function withoutTenantScope(): Builder
    {
        Log::warning('Bypassing tenant scope.', [
            'model' => static::class,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);

        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
