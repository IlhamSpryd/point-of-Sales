<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Context\TenantContext;
use App\Support\Traits\RequiresActiveStoreContext;
use DomainException;
use Tests\TestCase;

class TenantContextGuardTest extends TestCase
{
    use RequiresActiveStoreContext;

    protected bool $optOutFromDefaultTenant = true;

    public function test_it_resolves_store_from_allowed_tenant_stores_and_never_falls_back_to_store_1(): void
    {
        $context = app(TenantContext::class);
        $context->clear();
        $context->setTenantId(777001);
        $context->setStoreId(null);
        $context->setAllowedStoreIds([42, 43]);

        $resolvedStoreId = $this->resolveActiveStoreId(null, 777001);

        $this->assertSame(42, $resolvedStoreId);
        $this->assertNotSame(1, $resolvedStoreId);
    }

    public function test_it_throws_domain_exception_when_no_store_exists_for_tenant(): void
    {
        $context = app(TenantContext::class);
        $context->clear();
        $context->setAllowedStoreIds([]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Konteks cabang (Store) aktif tidak ditemukan');

        $this->resolveActiveStoreId(null, 99999999);
    }

    public function test_it_throws_domain_exception_when_no_device_exists_for_tenant(): void
    {
        $context = app(TenantContext::class);
        $context->clear();
        $context->setAllowedStoreIds([]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Perangkat kasir (Device) aktif tidak ditemukan');

        $this->resolveActiveDeviceId(null, null, 99999999, 99999999);
    }

    public function test_critical_pos_and_sales_files_contain_no_hardcoded_store_or_device_id_1_fallbacks(): void
    {
        $files = [
            base_path('app/Livewire/Kasir/CreateOrder.php'),
            base_path('app/Livewire/Kasir/OrderHistory.php'),
            base_path('app/Livewire/ShiftManager.php'),
            base_path('app/Modules/Sales/Controllers/TransactionController.php'),
            base_path('app/Modules/Sales/Services/ShiftService.php'),
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/(getStoreId\(\)|storeId|store_id|deviceId|device_id|created_by_device_id)\s*\?\?\s*1\b/',
                $contents,
                "Hardcoded ?? 1 fallback detected in {$file}"
            );
        }
    }
}
