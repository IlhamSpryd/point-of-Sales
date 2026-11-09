<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Core\Context\TenantContext;
use App\Models\User;
use App\Modules\Core\Models\Company;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class CompanyService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Create a new company under the active tenant.
     * Enforces multi-tenancy isolation and uniqueness of company_code per tenant.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createCompany(array $data, ?User $actor = null): Company
    {
        $tenantId = (int) ($actor ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        $companyName = trim((string) ($data['company_name'] ?? ''));
        if ($companyName === '') {
            throw ValidationException::withMessages([
                'company_name' => ['Company name is required.'],
            ]);
        }

        $companyCode = isset($data['company_code']) && trim((string) $data['company_code']) !== ''
            ? strtoupper(trim((string) $data['company_code']))
            : 'CMP-'.strtoupper(Str::random(6));

        // Check company code uniqueness per tenant
        $codeExists = Company::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('company_code', $companyCode)
            ->exists();

        if ($codeExists) {
            throw ValidationException::withMessages([
                'company_code' => ["Company code '{$companyCode}' already exists for this tenant."],
            ]);
        }

        $isDefault = (bool) ($data['is_default'] ?? false);

        return DB::transaction(function () use ($tenantId, $companyName, $companyCode, $data, $isDefault, $actor) {
            if ($isDefault) {
                // If setting as default, clear default from existing companies for this tenant
                Company::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            } else {
                // If this is the tenant's first company, force default = true
                $hasAny = Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists();
                if (! $hasAny) {
                    $isDefault = true;
                }
            }

            $company = Company::create([
                'tenant_id' => $tenantId,
                'company_code' => $companyCode,
                'company_name' => $companyName,
                'legal_name' => $data['legal_name'] ?? $companyName,
                'npwp' => $data['npwp'] ?? null,
                'tax_address' => $data['tax_address'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => $isDefault,
            ]);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'company.created',
                userId: $actor?->id ?? 1,
                storeId: $this->tenantContext->getStoreId(),
                subjectType: Company::class,
                subjectId: $company->id,
                description: "Created company entity: {$company->company_name} ({$company->company_code})",
                metadata: [
                    'company_id' => $company->id,
                    'is_default' => $company->is_default,
                ]
            );

            return $company;
        });
    }

    /**
     * Update existing company entity.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCompany(Company $company, array $data, ?User $actor = null): Company
    {
        $tenantId = (int) ($actor ? $actor->tenant_id : $this->tenantContext->requireTenantId());
        if ((int) $company->tenant_id !== $tenantId) {
            throw new RuntimeException('Unauthorized company modification for active tenant.');
        }

        return DB::transaction(function () use ($company, $data, $tenantId, $actor) {
            if (isset($data['is_default']) && (bool) $data['is_default'] && ! $company->is_default) {
                Company::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', '!=', $company->id)
                    ->update(['is_default' => false]);
                $company->is_default = true;
            }

            if (isset($data['company_name'])) {
                $company->company_name = trim((string) $data['company_name']);
            }
            if (isset($data['legal_name'])) {
                $company->legal_name = trim((string) $data['legal_name']);
            }
            if (array_key_exists('npwp', $data)) {
                $company->npwp = $data['npwp'];
            }
            if (array_key_exists('tax_address', $data)) {
                $company->tax_address = $data['tax_address'];
            }
            if (isset($data['is_active'])) {
                $company->is_active = (bool) $data['is_active'];
            }

            $company->save();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'company.updated',
                userId: $actor?->id ?? 1,
                storeId: $this->tenantContext->getStoreId(),
                subjectType: Company::class,
                subjectId: $company->id,
                description: "Updated company profile: {$company->company_name}",
                metadata: $company->only(['id', 'company_code', 'is_default', 'is_active'])
            );

            return $company;
        });
    }

    /**
     * Get default company for a tenant.
     */
    public function getDefaultCompany(int $tenantId): ?Company
    {
        return Company::getDefaultCompanyForTenant($tenantId);
    }

    /**
     * List all companies for active tenant.
     *
     * @return Collection<int, Company>
     */
    public function getCompaniesForTenant(int $tenantId): Collection
    {
        return Company::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }
}
