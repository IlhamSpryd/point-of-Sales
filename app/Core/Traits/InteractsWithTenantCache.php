<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Cache\TenantCacheNamespace;

/**
 * Trait InteractsWithTenantCache
 *
 * Provides streamlined access to tenant and company scoped cache operations.
 */
trait InteractsWithTenantCache
{
    protected ?TenantCacheNamespace $tenantCacheNamespace = null;

    protected function tenantCache(): TenantCacheNamespace
    {
        if ($this->tenantCacheNamespace === null) {
            $this->tenantCacheNamespace = app(TenantCacheNamespace::class);
        }

        return $this->tenantCacheNamespace;
    }
}
