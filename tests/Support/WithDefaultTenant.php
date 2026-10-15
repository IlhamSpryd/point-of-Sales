<?php

namespace Tests\Support;

use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Artisan;

trait WithDefaultTenant
{
    protected ?Tenant $defaultTenant = null;

    protected ?Store $defaultStore = null;

    protected function setUpWithDefaultTenant(): void
    {
        // Don't setup tenant context for isolated test classes
        if (isset($this->optOutFromDefaultTenant) && $this->optOutFromDefaultTenant) {
            return;
        }

        // Idempotent: jangan buat tenant default dua kali dan jangan timpa
        // konteks tenant yang sudah disiapkan oleh setUp() subclass test.
        // (Tests\TestCase memanggil hook ini SETELAH setUp() subclass.)
        $context = app(TenantContext::class);

        if ($this->defaultTenant !== null || $context->hasTenant()) {
            return;
        }

        $this->defaultTenant = Tenant::create(['name' => 'Default Test Tenant']);
        $this->defaultStore = Store::create([
            'tenant_id' => $this->defaultTenant->id,
            'name' => 'Default Test Store',
            'address' => 'Test Address',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->defaultTenant->id]);

        $context->setTenantId($this->defaultTenant->id);
        $context->setStoreId($this->defaultStore->id);
    }
}
