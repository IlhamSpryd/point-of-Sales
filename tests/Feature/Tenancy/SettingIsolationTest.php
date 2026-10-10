<?php

namespace Tests\Feature\Tenancy;

use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class SettingIsolationTest extends TestCase
{
    use RefreshDatabase;

    private $tenantA;

    private $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenantA = Tenant::forceCreate(['name' => 'Tenant A']);
            $this->tenantB = Tenant::forceCreate(['name' => 'Tenant B']);
        });
    }

    public function test_tenant_a_can_read_and_write_own_settings()
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        Setting::set('tax_rate', 10, 'integer');

        $value = Setting::get('tax_rate');
        $this->assertEquals(10, $value);

        // Verify DB
        $this->assertDatabaseHas('settings', [
            'tenant_id' => $this->tenantA->id,
            'key' => 'tax_rate',
            'value' => '10',
        ]);

        // Verify Cache
        $cachedValue = Cache::get("pos:setting:{$this->tenantA->id}:tax_rate");
        // Because of caching closure it caches the parsed value or the model?
        // Wait, Cache::remember caches the returned value (10).
        $this->assertEquals(10, $cachedValue);
    }

    public function test_tenant_b_cannot_read_tenant_a_settings()
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        Setting::set('printer_ip', '192.168.1.100');

        app(TenantContext::class)->setTenantId($this->tenantB->id);
        $valueB = Setting::get('printer_ip', 'default_ip');

        $this->assertEquals('default_ip', $valueB);
        $this->assertEquals('default_ip', Cache::get("pos:setting:{$this->tenantB->id}:printer_ip"));
    }

    public function test_same_key_can_have_different_values_for_different_tenants()
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        Setting::set('discount_active', 'true', 'boolean');

        app(TenantContext::class)->setTenantId($this->tenantB->id);
        Setting::set('discount_active', 'false', 'boolean');

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $this->assertTrue(Setting::get('discount_active'));

        app(TenantContext::class)->setTenantId($this->tenantB->id);
        $this->assertFalse(Setting::get('discount_active'));
    }

    public function test_update_by_tenant_a_does_not_overwrite_tenant_b()
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        Setting::set('tax_rate', 10, 'integer');

        app(TenantContext::class)->setTenantId($this->tenantB->id);
        Setting::set('tax_rate', 15, 'integer');

        // Update A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        Setting::set('tax_rate', 12, 'integer');

        // B should still be 15
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        $this->assertEquals(15, Setting::get('tax_rate'));

        // A is updated
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $this->assertEquals(12, Setting::get('tax_rate'));
    }

    public function test_cache_invalidation_after_update_is_isolated()
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        Setting::set('receipt_header', 'Header A');
        $this->assertEquals('Header A', Setting::get('receipt_header')); // Caches A

        app(TenantContext::class)->setTenantId($this->tenantB->id);
        Setting::set('receipt_header', 'Header B'); // Should not clear A's cache

        // Update B
        Setting::set('receipt_header', 'Header B Updated');
        $this->assertEquals('Header B Updated', Setting::get('receipt_header')); // Caches B Updated

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $this->assertEquals('Header A', Setting::get('receipt_header')); // A's cache is still valid
    }

    public function test_query_without_tenant_context_fails_closed()
    {
        app(TenantContext::class)->clear();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant context has not been set');

        // Context is clear by default
        Setting::get('tax_rate');
    }
}
