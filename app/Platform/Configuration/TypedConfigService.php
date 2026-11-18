<?php

declare(strict_types=1);

namespace App\Platform\Configuration;

use App\Core\Context\TenantContext;
use App\Platform\Authorization\Scope;
use Illuminate\Support\Facades\Cache;

/**
 * High-performance typed configuration access service with scope resolution and caching (EXT-02, ADR-112).
 */
class TypedConfigService
{
    /**
     * @var array<string, mixed>
     */
    protected array $runtimeOverrides = [];

    public function __construct(
        protected TenantContext $tenantContext,
    ) {
        TypedConfigRegistry::boot();
    }

    /**
     * Retrieves a typed configuration value by key, resolving scope and default fallback.
     */
    public function get(string $key, ?int $scopeId = null, ?Scope $scope = null): mixed
    {
        $definition = TypedConfigRegistry::get($key);

        $resolvedScope = $scope ?? $definition->scope;
        $resolvedScopeId = $scopeId ?? match ($resolvedScope) {
            Scope::TENANT => $this->tenantContext->getTenantId(),
            Scope::COMPANY => $this->tenantContext->getCompanyId(),
            Scope::BRANCH => $this->tenantContext->getStoreId(),
            Scope::SELF => null,
        };

        $overrideKey = $this->buildCacheKey($key, $resolvedScope, $resolvedScopeId);
        if (array_key_exists($overrideKey, $this->runtimeOverrides)) {
            return $definition->castValue($this->runtimeOverrides[$overrideKey]);
        }

        if (function_exists('app') && app()->bound('cache')) {
            $cached = Cache::get($overrideKey);
            if ($cached !== null) {
                return $definition->castValue($cached);
            }
        }

        return $definition->default;
    }

    /**
     * Sets a runtime or persistent override for a configuration key under a specific scope.
     */
    public function set(string $key, mixed $value, ?int $scopeId = null, ?Scope $scope = null): void
    {
        $definition = TypedConfigRegistry::get($key);
        $castValue = $definition->castValue($value);

        $resolvedScope = $scope ?? $definition->scope;
        $resolvedScopeId = $scopeId ?? match ($resolvedScope) {
            Scope::TENANT => $this->tenantContext->getTenantId(),
            Scope::COMPANY => $this->tenantContext->getCompanyId(),
            Scope::BRANCH => $this->tenantContext->getStoreId(),
            Scope::SELF => null,
        };

        $cacheKey = $this->buildCacheKey($key, $resolvedScope, $resolvedScopeId);
        $this->runtimeOverrides[$cacheKey] = $castValue;

        if (function_exists('app') && app()->bound('cache')) {
            Cache::put($cacheKey, $castValue, 3600);
        }
    }

    /**
     * Validates and casts an arbitrary value against the definition for the specified key.
     */
    public function validate(string $key, mixed $value): mixed
    {
        return TypedConfigRegistry::get($key)->castValue($value);
    }

    /**
     * Clears all runtime and cached configuration overrides for this request.
     */
    public function clear(): void
    {
        $this->runtimeOverrides = [];
    }

    protected function buildCacheKey(string $key, Scope $scope, ?int $scopeId): string
    {
        $idPart = $scopeId !== null ? (string) $scopeId : 'global';

        return "typed_config:{$scope->value}:{$idPart}:{$key}";
    }
}
