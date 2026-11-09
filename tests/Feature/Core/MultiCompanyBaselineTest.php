<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\TenantProvisioningService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiCompanyBaselineTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private User $user;
    private CompanyService $companyService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->companyService = app(CompanyService::class);
    }

    public function test_default_company_exists_for_provisioned_tenant(): void
    {
        $defaultCompany = Company::getDefaultCompanyForTenant($this->tenant->id);

        $this->assertNotNull($defaultCompany);
        $this->assertTrue($defaultCompany->is_default);
        $this->assertTrue($defaultCompany->is_active);
        $this->assertSame($this->tenant->id, (int) $defaultCompany->tenant_id);
    }

    public function test_can_create_additional_company_under_authorized_workflow(): void
    {
        $company = $this->companyService->createCompany([
            'company_name' => 'PT Cabang Ekspansi Perkasa',
            'company_code' => 'CMP-EXP-01',
            'legal_name' => 'PT Cabang Ekspansi Perkasa Tbk',
            'npwp' => '01.234.567.8-123.000',
            'tax_address' => 'Jl. Jenderal Sudirman Kav. 52-53, Jakarta Selatan',
            'is_active' => true,
            'is_default' => false,
        ], $this->user);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'tenant_id' => $this->tenant->id,
            'company_code' => 'CMP-EXP-01',
            'is_default' => false,
        ]);

        // Default company remains unchanged
        $defaultCompany = Company::getDefaultCompanyForTenant($this->tenant->id);
        $this->assertNotSame($defaultCompany->id, $company->id);
    }

    public function test_company_code_uniqueness_is_enforced_per_tenant(): void
    {
        $this->companyService->createCompany([
            'company_name' => 'PT Pertama',
            'company_code' => 'CMP-UNIQUE-01',
        ], $this->user);

        $this->expectException(ValidationException::class);

        $this->companyService->createCompany([
            'company_name' => 'PT Duplikat',
            'company_code' => 'CMP-UNIQUE-01',
        ], $this->user);
    }

    public function test_setting_new_default_company_switches_default_flag_cleanly(): void
    {
        $firstDefault = Company::getDefaultCompanyForTenant($this->tenant->id);

        $secondCompany = $this->companyService->createCompany([
            'company_name' => 'PT Holding Utama Baru',
            'company_code' => 'CMP-HOLD-02',
            'is_default' => true,
        ], $this->user);

        $this->assertTrue($secondCompany->fresh()->is_default);
        $this->assertFalse($firstDefault->fresh()->is_default);

        $currentDefault = Company::getDefaultCompanyForTenant($this->tenant->id);
        $this->assertSame($secondCompany->id, $currentDefault->id);
    }

    public function test_tenant_provisioning_service_creates_default_company_automatically(): void
    {
        $provisioningService = app(TenantProvisioningService::class);

        $result = $provisioningService->provisionTenant(
            tenantName: 'Restoran Bintang Lima Multi PT',
            ownerName: 'Alexander Owner',
            ownerEmail: 'alex.owner.'.uniqid().'@testprov.id',
            ownerPhone: '081299887766'
        );

        $newTenant = $result['tenant'];

        $defaultCompany = Company::getDefaultCompanyForTenant($newTenant->id);
        $this->assertNotNull($defaultCompany);
        $this->assertTrue($defaultCompany->is_default);
        $this->assertSame('Restoran Bintang Lima Multi PT', $defaultCompany->company_name);
    }

    public function test_middleware_resolves_and_binds_default_company_to_tenant_context(): void
    {
        $middleware = app(\App\Http\Middleware\SetTenantContext::class);
        $tenantContext = app(TenantContext::class);

        // Reset context
        $tenantContext->clear();
        $this->assertNull($tenantContext->getCompanyId());

        $request = \Illuminate\Http\Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $this->user);

        // Laravel Session store
        $session = app('session.store');
        $session->start();
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, function ($req) use ($tenantContext) {
            // Verify context inside request lifecycle
            $this->assertSame($this->tenant->id, $tenantContext->getTenantId());
            $this->assertNotNull($tenantContext->getCompanyId());
            $this->assertGreaterThan(0, $tenantContext->requireCompanyId());

            return response('OK');
        });

        $this->assertSame('OK', $response->getContent());
        $this->assertTrue($session->has('active_company_id'));
        $this->assertSame($tenantContext->getCompanyId(), $session->get('active_company_id'));
    }
}
