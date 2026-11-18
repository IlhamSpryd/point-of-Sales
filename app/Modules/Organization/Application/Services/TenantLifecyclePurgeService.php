<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Services;

use App\Modules\Organization\Models\Tenant;
use App\Platform\Audit\ImmutableAuditWriter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise UU PDP Compliant Tenant Purge and PII Anonymization Service (EXT-06).
 * Irreversibly anonymizes personal data while strictly preserving aggregate fiscal and double-entry general ledger records.
 */
class TenantLifecyclePurgeService
{
    public function __construct(
        private readonly ImmutableAuditWriter $auditWriter,
    ) {}

    /**
     * Purges and anonymizes an ARCHIVED tenant in compliance with UU PDP.
     *
     * @throws DomainException
     */
    public function purgeTenant(int $tenantId): Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            throw new DomainException("Tenant dengan ID {$tenantId} tidak ditemukan.");
        }

        if (! $tenant->isArchived()) {
            throw new DomainException("Hanya tenant berstatus ARCHIVED yang dapat di-purge. Status tenant saat ini: '{$tenant->status}'.");
        }

        return DB::transaction(function () use ($tenant) {
            $tenantId = (int) $tenant->id;

            // 1. Anonymize users belonging to tenant
            $this->anonymizeUsers($tenantId);

            // 2. Anonymize customers belonging to tenant
            $this->anonymizeCustomers($tenantId);

            // 3. Anonymize employees and related sensitive profiles
            $this->anonymizeEmployees($tenantId);

            // 4. Update tenant status and access_status to purged
            $tenant->update([
                'status' => Tenant::STATUS_PURGED,
                'access_status' => Tenant::ACCESS_PURGED,
            ]);

            // 5. Immutable audit entry
            $this->auditWriter->write(
                tenantId: $tenantId,
                action: 'tenant.purged',
                userId: auth()->id(),
                subjectType: 'tenant',
                subjectId: $tenantId,
                description: "Tenant '{$tenant->name}' (ID: {$tenantId}) was permanently purged and PII anonymized per UU PDP compliance.",
                payload: [
                    'tenant_id' => $tenantId,
                    'tenant_name' => $tenant->name,
                    'purged_at' => now()->toIso8601String(),
                ]
            );

            return $tenant->fresh();
        });
    }

    private function anonymizeUsers(int $tenantId): void
    {
        $users = DB::table('users')->where('tenant_id', $tenantId)->get(['id', 'email']);

        foreach ($users as $user) {
            $hash = substr(hash('sha256', (string) $user->id.'-'.$user->email), 0, 12);
            $anonEmail = "purged-user-{$user->id}-{$hash}@anonymized.internal";

            DB::table('users')->where('id', $user->id)->update([
                'name' => 'Anonymized User '.$user->id,
                'email' => $anonEmail,
                'email_normalized' => $anonEmail,
                'phone_number' => null,
                'password' => '*',
                'password_hash' => null,
                'pin_hash' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'remember_token' => null,
                'is_active' => false,
                'deleted_at' => now(),
            ]);
        }
    }

    private function anonymizeCustomers(int $tenantId): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        $customers = DB::table('customers')->where('tenant_id', $tenantId)->get(['id']);

        foreach ($customers as $customer) {
            DB::table('customers')->where('id', $customer->id)->update([
                'name' => 'Anonymized Customer '.$customer->id,
                'phone' => null,
                'email' => "purged-cust-{$customer->id}@anonymized.internal",
                'tax_id' => null,
                'business_registration_no' => null,
                'is_active' => false,
                'deleted_at' => now(),
            ]);
        }
    }

    private function anonymizeEmployees(int $tenantId): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        $employees = DB::table('employees')->where('tenant_id', $tenantId)->get(['id']);

        foreach ($employees as $employee) {
            DB::table('employees')->where('id', $employee->id)->update([
                'full_name' => 'Anonymized Employee '.$employee->id,
                'nickname' => null,
                'national_id_number' => null,
                'email' => "purged-emp-{$employee->id}@anonymized.internal",
                'phone_number' => '0000000000',
                'residential_address' => null,
                'emergency_contact_name' => null,
                'emergency_contact_phone' => null,
                'emergency_contact_relation' => null,
                'employment_status' => 'TERMINATED',
                'termination_reason' => 'TENANT_PURGED',
                'termination_date' => now()->toDateString(),
                'deleted_at' => now(),
            ]);
        }

        if (Schema::hasTable('employee_bank_accounts')) {
            DB::table('employee_bank_accounts')
                ->where('tenant_id', $tenantId)
                ->update([
                    'account_number' => 'ANON-BANK-ACCOUNT',
                    'account_holder_name' => 'ANON-HOLDER',
                    'is_active' => false,
                ]);
        }

        if (Schema::hasTable('employee_tax_profiles')) {
            DB::table('employee_tax_profiles')
                ->where('tenant_id', $tenantId)
                ->update([
                    'tax_identification_number' => 'ANON-NPWP',
                ]);
        }
    }
}
