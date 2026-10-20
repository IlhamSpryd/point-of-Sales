<?php

declare(strict_types=1);

// Rancang ulang strategi cache katalog menu -- Node 3 Performance & Caching | 2026-09-22

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MenuCacheService: lapisan cache-aside untuk katalog menu (Kasir & Self-Order).
 */
class MenuCacheService
{
    private const TTL_SECONDS = 3600;

    private const KEY_CATALOG = 'pos:menu:catalog:v2';

    private const KEY_CATEGORIES = 'pos:menu:categories:v2';

    private const KEY_ACTIVE_PRODUCTS = 'pos:menu:active-products:v2';

    private const KEY_MENU_DISPLAY = 'pos:menu:display:v2';

    private const REBUILD_LOCK_KEY = 'pos:menu:rebuild-lock';

    private const REBUILD_LOCK_WAIT_SECONDS = 5;

    private function getCache(?int $tenantId = null)
    {
        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        // D1-DB-008: fail-closed -- tolak pembacaan/penulisan cache tanpa tenant.
        if ($tenantId === null) {
            throw new \RuntimeException('MenuCacheService requires an active tenant context.');
        }

        return Cache::supportsTags() ? Cache::tags(["menu:tenant_{$tenantId}"]) : Cache::store();
    }

    public function getCatalog()
    {
        $key = $this->getTenantKey(self::KEY_CATALOG);

        return $this->getCache()->remember($key, self::TTL_SECONDS, function () {
            $context = app(TenantContext::class);
            $storeId = $context->getStoreId();

            $categories = Category::with([
                'products' => function ($q) use ($storeId) {
                    $q->availableForOrder()->with(['modifierGroups.modifiers', 'storeAssortments']);
                    if ($storeId) {
                        $q->whereDoesntHave('storeAssortments', function ($a) use ($storeId) {
                            $a->where('store_id', $storeId)->where('is_available', false);
                        });
                    }
                },
            ])->get();

            if ($storeId) {
                $resolver = app(CatalogPriceResolverService::class);
                foreach ($categories as $cat) {
                    foreach ($cat->products as $prod) {
                        if ($prod instanceof Product) {
                            $prod->product_price = (int) round($resolver->resolvePrice($prod, $storeId));
                        }
                    }
                }
            }

            return $categories;
        });
    }

    public function getAllCategories()
    {
        $key = $this->getTenantKey(self::KEY_CATEGORIES);

        return $this->getCache()->remember($key, self::TTL_SECONDS, function () {
            return Category::all();
        });
    }

    public function getActiveProductsWithCategory()
    {
        $key = $this->getTenantKey(self::KEY_ACTIVE_PRODUCTS);

        return $this->getCache()->remember($key, self::TTL_SECONDS, function () {
            return Product::where('is_active', true)->where('status', 'active')->with('category')->get();
        });
    }

    public function getMenuDisplayData(): array
    {
        $key = $this->getTenantKey(self::KEY_MENU_DISPLAY);
        $cached = $this->getCache()->get($key);

        if ($cached !== null) {
            return $cached;
        }

        try {
            return Cache::lock($this->getTenantKey(self::REBUILD_LOCK_KEY), 10)
                ->block(self::REBUILD_LOCK_WAIT_SECONDS, function () use ($key) {
                    return $this->getCache()->remember(
                        $key,
                        self::TTL_SECONDS,
                        fn () => $this->buildMenuDisplayData()
                    );
                });
        } catch (LockTimeoutException) {
            Log::warning('[OMEGA-NODE3] Menu display cache rebuild lock timeout -- fallback ke query langsung.');

            return $this->buildMenuDisplayData();
        }
    }

    private function buildMenuDisplayData(): array
    {
        $context = app(TenantContext::class);
        $storeId = $context->getStoreId();

        $productQuery = Product::where('is_active', true)
            ->where('status', 'active')
            ->with(['category', 'modifierGroups.modifiers', 'storeAssortments']);

        if ($storeId) {
            $productQuery->whereDoesntHave('storeAssortments', function ($a) use ($storeId) {
                $a->where('store_id', $storeId)->where('is_available', false);
            });
        }

        $products = $productQuery->get();

        if ($storeId) {
            $resolver = app(CatalogPriceResolverService::class);
            foreach ($products as $prod) {
                $prod->product_price = (int) round($resolver->resolvePrice($prod, $storeId));
            }
        }

        return [
            'categories' => Category::all(),
            'products' => $products,
        ];
    }

    /**
     * Invalidasi PENUH seluruh cache katalog menu per tenant & store.
     */
    public function flush(?int $tenantId = null, ?int $storeId = null): void
    {
        $context = app(TenantContext::class);
        $resolvedTenantId = $tenantId ?? $context->getTenantId();

        if ($resolvedTenantId === null) {
            throw new \RuntimeException('MenuCacheService::flush() requires a tenant id.');
        }

        if (Cache::supportsTags()) {
            Cache::tags(["menu:tenant_{$resolvedTenantId}"])->flush();

            return;
        }

        if ($storeId !== null) {
            $this->forgetStoreKeys($resolvedTenantId, $storeId);
            $this->forgetStoreKeys($resolvedTenantId, null);
        } else {
            $this->forgetStoreKeys($resolvedTenantId, null);
            $storeIds = Store::where('tenant_id', $resolvedTenantId)->pluck('id');
            foreach ($storeIds as $sid) {
                $this->forgetStoreKeys($resolvedTenantId, (int) $sid);
            }
        }
    }

    private function forgetStoreKeys(int $tenantId, ?int $storeId): void
    {
        $cache = $this->getCache($tenantId);
        $cache->forget($this->formatKey(self::KEY_CATALOG, $tenantId, $storeId));
        $cache->forget($this->formatKey(self::KEY_CATEGORIES, $tenantId, $storeId));
        $cache->forget($this->formatKey(self::KEY_ACTIVE_PRODUCTS, $tenantId, $storeId));
        $cache->forget($this->formatKey(self::KEY_MENU_DISPLAY, $tenantId, $storeId));
    }

    private function formatKey(string $key, int $tenantId, ?int $storeId): string
    {
        return str_replace('pos:menu:', "pos:menu:{$tenantId}:".($storeId !== null ? "store:{$storeId}:" : 'all:'), $key);
    }

    private function getTenantKey(string $key, ?int $tenantId = null, ?int $storeId = null): string
    {
        $context = app(TenantContext::class);
        $tenantId = $tenantId ?? $context->getTenantId();

        if ($tenantId === null) {
            throw new \RuntimeException('MenuCacheService cache key requires an active tenant context.');
        }

        $storeId = $storeId ?? $context->getStoreId();

        return $this->formatKey($key, $tenantId, $storeId);
    }
}
