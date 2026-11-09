<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use App\Core\Cache\TenantCacheNamespace;
use App\Core\Context\TenantContext;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class TenantCacheNamespaceTest extends TestCase
{
    private TenantContext $context;
    private TenantCacheNamespace $cacheNamespace;

    protected function afterSubclassSetUp(): void
    {
        $this->context = new TenantContext;
        $this->cacheNamespace = new TenantCacheNamespace($this->context);
    }

    public function test_throws_exception_when_tenant_context_is_not_set(): void
    {
        $this->expectException(RuntimeException::class);
        $this->cacheNamespace->tenantKey('dashboard:metrics');
    }

    public function test_generates_isolated_tenant_key(): void
    {
        $this->context->setTenantId(42);

        $key = $this->cacheNamespace->tenantKey('dashboard:metrics');
        $this->assertSame('t:42:dashboard:metrics', $key);
    }

    public function test_generates_isolated_company_key(): void
    {
        $this->context->setTenantId(42);
        $this->context->setCompanyId(7);

        $key = $this->cacheNamespace->companyKey('tax:rates');
        $this->assertSame('t:42:c:7:tax:rates', $key);

        // Explicit companyId override
        $overrideKey = $this->cacheNamespace->companyKey('tax:rates', 99);
        $this->assertSame('t:42:c:99:tax:rates', $overrideKey);
    }

    public function test_generates_isolated_store_key(): void
    {
        $this->context->setTenantId(42);
        $this->context->setCompanyId(7);
        $this->context->setStoreId(101);

        $key = $this->cacheNamespace->storeKey('shift:summary');
        $this->assertSame('t:42:c:7:s:101:shift:summary', $key);
    }

    public function test_generates_global_key(): void
    {
        $key = $this->cacheNamespace->globalKey('countries:list');
        $this->assertSame('global:countries:list', $key);
    }

    public function test_tenant_cache_operations_prevent_cross_tenant_pollution(): void
    {
        $this->context->setTenantId(10);
        $this->cacheNamespace->putTenant('setting:theme', 'dark');

        $this->assertSame('dark', $this->cacheNamespace->getTenant('setting:theme'));

        // Switch to Tenant 20
        $this->context->setTenantId(20);
        $this->assertNull($this->cacheNamespace->getTenant('setting:theme'));

        // Set value for Tenant 20
        $this->cacheNamespace->putTenant('setting:theme', 'light');
        $this->assertSame('light', $this->cacheNamespace->getTenant('setting:theme'));

        // Verify Tenant 10 still has original dark value
        $this->context->setTenantId(10);
        $this->assertSame('dark', $this->cacheNamespace->getTenant('setting:theme'));
    }

    public function test_company_cache_operations_prevent_cross_company_pollution(): void
    {
        $this->context->setTenantId(10);
        $this->context->setCompanyId(1);
        $this->cacheNamespace->putCompany('tax:npwp_header', 'PT Satu NPWP');

        $this->assertSame('PT Satu NPWP', $this->cacheNamespace->getCompany('tax:npwp_header'));

        // Switch company under same tenant
        $this->context->setCompanyId(2);
        $this->assertNull($this->cacheNamespace->getCompany('tax:npwp_header'));

        $this->cacheNamespace->putCompany('tax:npwp_header', 'CV Dua NPWP');
        $this->assertSame('CV Dua NPWP', $this->cacheNamespace->getCompany('tax:npwp_header'));

        // Switch back to company 1
        $this->context->setCompanyId(1);
        $this->assertSame('PT Satu NPWP', $this->cacheNamespace->getCompany('tax:npwp_header'));
    }
}
