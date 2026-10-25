<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class MenuCacheServiceTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected MenuCacheService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
        $this->service = app(MenuCacheService::class);
    }

    public function test_fails_closed_when_tenant_context_is_missing(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(\RuntimeException::class);
        $this->service->getCatalog();
    }

    public function test_flush_fails_closed_when_tenant_id_is_missing(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MenuCacheService::flush() requires a tenant id.');
        $this->service->flush();
    }

    public function test_get_catalog_caches_and_returns_categories_with_products(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->productA->update(['stock' => 10, 'is_active' => true]);

        // First call populates cache
        $catalog = $this->service->getCatalog();
        $this->assertNotEmpty($catalog);
        $this->assertTrue($catalog->contains('category_name', 'Cat A'));
        $catA = $catalog->firstWhere('category_name', 'Cat A');
        $this->assertTrue($catA->products->contains('product_name', 'Prod A'));

        // Add a new category directly to database without flushing cache
        Category::withoutTenantScope()->create([
            'category_name' => 'Uncached Category A',
            'tenant_id' => $this->tenantA->id,
        ]);

        // Second call should return cached data (without 'Uncached Category A')
        $cachedCatalog = $this->service->getCatalog();
        $this->assertFalse($cachedCatalog->contains('category_name', 'Uncached Category A'));

        // After flushing, new category should appear
        $this->service->flush();
        $freshCatalog = $this->service->getCatalog();
        $this->assertTrue($freshCatalog->contains('category_name', 'Uncached Category A'));
    }

    public function test_get_all_categories_caches_categories(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $categories = $this->service->getAllCategories();
        $this->assertTrue($categories->contains('category_name', 'Cat A'));

        Category::withoutTenantScope()->create([
            'category_name' => 'Brand New Cat',
            'tenant_id' => $this->tenantA->id,
        ]);

        // Cache hit: does not have the new category yet
        $cachedCategories = $this->service->getAllCategories();
        $this->assertFalse($cachedCategories->contains('category_name', 'Brand New Cat'));

        // After flush: new category is loaded
        $this->service->flush();
        $freshCategories = $this->service->getAllCategories();
        $this->assertTrue($freshCategories->contains('category_name', 'Brand New Cat'));
    }

    public function test_get_active_products_with_category_only_returns_active_products(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $catA = Category::where('tenant_id', $this->tenantA->id)->first();

        Product::withoutTenantScope()->create([
            'product_name' => 'Inactive Prod A',
            'tenant_id' => $this->tenantA->id,
            'category_id' => $catA->id,
            'is_active' => false,
            'stock' => 10,
        ]);

        $products = $this->service->getActiveProductsWithCategory();
        $this->assertTrue($products->contains('product_name', 'Prod A'));
        $this->assertFalse($products->contains('product_name', 'Inactive Prod A'));
        $this->assertNotNull($products->first()->category);
    }

    public function test_get_menu_display_data_caches_and_returns_categories_and_products(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $displayData = $this->service->getMenuDisplayData();
        $this->assertArrayHasKey('categories', $displayData);
        $this->assertArrayHasKey('products', $displayData);
        $this->assertTrue($displayData['categories']->contains('category_name', 'Cat A'));
        $this->assertTrue($displayData['products']->contains('product_name', 'Prod A'));

        // Second call should return identical cached data
        $cachedDisplayData = $this->service->getMenuDisplayData();
        $this->assertCount(count($displayData['categories']), $cachedDisplayData['categories']);
        $this->assertCount(count($displayData['products']), $cachedDisplayData['products']);
    }

    public function test_get_menu_display_data_falls_back_when_lock_times_out(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $mockLock = \Mockery::mock();
        $mockLock->shouldReceive('block')
            ->once()
            ->andThrow(new LockTimeoutException('Rebuild lock timed out'));

        $mockStore = \Mockery::mock(Repository::class);
        $mockStore->shouldReceive('get')->once()->andReturn(null);

        Cache::shouldReceive('supportsTags')->andReturn(false);
        Cache::shouldReceive('store')->andReturn($mockStore);
        Cache::shouldReceive('lock')->once()->andReturn($mockLock);

        $result = $this->service->getMenuDisplayData();
        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('products', $result);
        $this->assertTrue($result['categories']->contains('category_name', 'Cat A'));
        $this->assertTrue($result['products']->contains('product_name', 'Prod A'));
    }

    public function test_flush_clears_cache_for_specific_store(): void
    {
        $context = app(TenantContext::class);

        // Populate cache for Store A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->service->getCatalog();

        // Populate cache for Store A2
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA2->id);
        $this->service->getCatalog();

        // Flush only Store A
        $this->service->flush($this->tenantA->id, $this->storeA->id);

        // Store A can rebuild its catalog smoothly
        $context->setStoreId($this->storeA->id);
        $catalogA = $this->service->getCatalog();
        $this->assertNotNull($catalogA);
    }

    public function test_flush_clears_cache_for_all_stores_when_store_id_is_null(): void
    {
        $context = app(TenantContext::class);

        // Populate cache for Store A and Store A2
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->service->getCatalog();

        $context->setStoreId($this->storeA2->id);
        $this->service->getCatalog();

        // Flush all stores of Tenant A
        $this->service->flush($this->tenantA->id, null);

        // Both stores rebuild without issue
        $context->setStoreId($this->storeA->id);
        $this->assertNotNull($this->service->getCatalog());

        $context->setStoreId($this->storeA2->id);
        $this->assertNotNull($this->service->getCatalog());
    }

    public function test_cache_isolation_between_tenants(): void
    {
        $context = app(TenantContext::class);

        // Tenant A sees only Cat A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $catA = $this->service->getCatalog();
        $this->assertTrue($catA->contains('category_name', 'Cat A'));
        $this->assertFalse($catA->contains('category_name', 'Cat B'));

        // Tenant B sees only Cat B
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $catB = $this->service->getCatalog();
        $this->assertTrue($catB->contains('category_name', 'Cat B'));
        $this->assertFalse($catB->contains('category_name', 'Cat A'));

        // Flushing Tenant A does not clear or affect Tenant B
        $this->service->flush($this->tenantA->id);

        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $catBAfterFlushA = $this->service->getCatalog();
        $this->assertTrue($catBAfterFlushA->contains('category_name', 'Cat B'));
        $this->assertFalse($catBAfterFlushA->contains('category_name', 'Cat A'));
    }
}
