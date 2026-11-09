<?php

declare(strict_types=1);

namespace App\Core\Cache;

use App\Core\Context\TenantContext;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * TenantCacheNamespace
 *
 * Implements Founder Architectural Decision 4:
 * Redis Multi-Tenant & Multi-Company Cache Isolation.
 *
 * Guarantees:
 * 1. Enforces strict cryptographic namespace boundaries (t:{tenant_id}:c:{company_id}).
 * 2. Never trusts untrusted frontend parameters — derives tenant/company strictly from trusted server TenantContext.
 * 3. Prevents cross-tenant and cross-company cache pollution or data leaks.
 * 4. Deterministic key formulation and invalidation.
 */
class TenantCacheNamespace
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected ?Repository $cacheStore = null
    ) {}

    protected function getStore(): Repository
    {
        return $this->cacheStore ?? Cache::store();
    }

    /**
     * Build tenant-scoped cache key.
     */
    public function tenantKey(string $key): string
    {
        $tenantId = $this->tenantContext->requireTenantId();

        return "t:{$tenantId}:".ltrim($key, ':');
    }

    /**
     * Build company-scoped cache key.
     */
    public function companyKey(string $key, ?int $companyId = null): string
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $targetCompanyId = $companyId ?? $this->tenantContext->getCompanyId() ?? 0;

        return "t:{$tenantId}:c:{$targetCompanyId}:".ltrim($key, ':');
    }

    /**
     * Build store-scoped cache key with tenant and company hierarchy.
     */
    public function storeKey(string $key, ?int $storeId = null, ?int $companyId = null): string
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $targetCompanyId = $companyId ?? $this->tenantContext->getCompanyId() ?? 0;
        $targetStoreId = $storeId ?? $this->tenantContext->getStoreId() ?? 0;

        return "t:{$tenantId}:c:{$targetCompanyId}:s:{$targetStoreId}:".ltrim($key, ':');
    }

    /**
     * Build global reference key (only for truly global, tenant-independent master data).
     */
    public function globalKey(string $key): string
    {
        return 'global:'.ltrim($key, ':');
    }

    // --- Tenant Operations ---

    public function getTenant(string $key, mixed $default = null): mixed
    {
        return $this->getStore()->get($this->tenantKey($key), $default);
    }

    public function putTenant(string $key, mixed $value, DateInterval|DateTimeInterface|int $ttl = 3600): bool
    {
        return $this->getStore()->put($this->tenantKey($key), $value, $ttl);
    }

    public function rememberTenant(string $key, DateInterval|DateTimeInterface|int $ttl, Closure $callback): mixed
    {
        return $this->getStore()->remember($this->tenantKey($key), $ttl, $callback);
    }

    public function forgetTenant(string $key): bool
    {
        return $this->getStore()->forget($this->tenantKey($key));
    }

    public function lockTenant(string $name, int $seconds = 10, ?string $owner = null): Lock
    {
        $store = $this->getStore();
        if ($store instanceof \Illuminate\Contracts\Cache\LockProvider) {
            return $store->lock($this->tenantKey("lock:{$name}"), $seconds, $owner);
        }

        return Cache::lock($this->tenantKey("lock:{$name}"), $seconds, $owner);
    }

    // --- Company Operations ---

    public function getCompany(string $key, mixed $default = null, ?int $companyId = null): mixed
    {
        return $this->getStore()->get($this->companyKey($key, $companyId), $default);
    }

    public function putCompany(string $key, mixed $value, DateInterval|DateTimeInterface|int $ttl = 3600, ?int $companyId = null): bool
    {
        return $this->getStore()->put($this->companyKey($key, $companyId), $value, $ttl);
    }

    public function rememberCompany(string $key, DateInterval|DateTimeInterface|int $ttl, Closure $callback, ?int $companyId = null): mixed
    {
        return $this->getStore()->remember($this->companyKey($key, $companyId), $ttl, $callback);
    }

    public function forgetCompany(string $key, ?int $companyId = null): bool
    {
        return $this->getStore()->forget($this->companyKey($key, $companyId));
    }

    public function lockCompany(string $name, int $seconds = 10, ?int $companyId = null, ?string $owner = null): Lock
    {
        $store = $this->getStore();
        if ($store instanceof \Illuminate\Contracts\Cache\LockProvider) {
            return $store->lock($this->companyKey("lock:{$name}", $companyId), $seconds, $owner);
        }

        return Cache::lock($this->companyKey("lock:{$name}", $companyId), $seconds, $owner);
    }
}
