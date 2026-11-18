<?php

namespace Tests\Support;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

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

        // Avoid database overhead and locking in unit tests that do not declare database traits
        if (str_starts_with(static::class, 'Tests\\Unit\\')) {
            $uses = array_flip(class_uses_recursive(static::class));
            $hasDbTrait = isset($uses[DatabaseTransactions::class])
                || isset($uses[RefreshDatabase::class])
                || isset($uses[DatabaseTruncation::class]);

            if (! $hasDbTrait) {
                return;
            }
        }

        // Idempotent: jangan buat tenant default dua kali dan jangan timpa
        // konteks tenant yang sudah disiapkan oleh setUp() subclass test.
        // (Tests\TestCase memanggil hook ini SETELAH setUp() subclass.)
        $context = app(TenantContext::class);

        if ($this->defaultTenant !== null || $context->hasTenant()) {
            return;
        }

        $this->defaultTenant = Tenant::create([
            'name' => 'Default Test Tenant '.Str::random(8),
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);
        $this->defaultStore = Store::create([
            'tenant_id' => $this->defaultTenant->id,
            'name' => 'Default Test Store',
            'address' => 'Test Address',
        ]);

        $defaultCompany = Company::create([
            'tenant_id' => $this->defaultTenant->id,
            'company_code' => 'CMP-DEF',
            'company_name' => 'Default Company',
            'legal_name' => 'Default Legal Entity',
            'is_active' => true,
            'is_default' => true,
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->defaultTenant->id]);

        $context->setTenantId($this->defaultTenant->id);
        $context->setCompanyId($defaultCompany->id);
        $context->setStoreId($this->defaultStore->id);
    }
}
