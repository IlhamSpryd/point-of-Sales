<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\PtkpStatusEnum;
use App\Modules\HR\Enums\TaxCalculationMethodEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTaxProfile;
use App\Modules\HR\Models\JobTitle;
use App\Services\Context\TenantContext;
use App\Services\EmployeeIdGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillEmployeesFromUsersCommand extends Command
{
    protected $signature = 'hr:backfill-employees-from-users
                            {--tenant= : Specific tenant ID to backfill}
                            {--dry-run : Simulate without persisting changes}';

    protected $description = 'Backfill employee profiles from existing staff users';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $isDryRun = (bool) $this->option('dry-run');

        $tenantQuery = Tenant::query();
        if ($tenantId) {
            $tenantQuery->where('id', (int) $tenantId);
        }

        $tenants = $tenantQuery->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found matching criteria.');

            return self::SUCCESS;
        }

        $totalBackfilled = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing Tenant [{$tenant->id}] - {$tenant->name}...");

            app(TenantContext::class)->runAs((int) $tenant->id, null, function () use ($tenant, $isDryRun, &$totalBackfilled) {
                // 1. Ensure default department exists
                $defaultDept = Department::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'department_code' => 'OPS'],
                    [
                        'name' => 'Operations & Store Operations',
                        'description' => 'Default operations department auto-created by backfill',
                        'is_active' => true,
                    ]
                );

                // 2. Fetch staff users needing backfill
                // Exclude system accounts starting with SYS-
                $existingEmployeeUserIds = Employee::whereNotNull('user_id')->pluck('user_id')->all();

                $users = User::where('tenant_id', $tenant->id)
                    ->whereNotIn('id', $existingEmployeeUserIds)
                    ->where(function ($q) {
                        $q->whereNull('employee_id')
                            ->orWhere('employee_id', 'not like', 'SYS-%');
                    })
                    ->with('role')
                    ->get();

                if ($users->isEmpty()) {
                    $this->line("  No users need backfilling in Tenant [{$tenant->id}].");

                    return;
                }

                $this->line("  Found {$users->count()} candidate user(s) to backfill.");

                DB::transaction(function () use ($users, $tenant, $defaultDept, $isDryRun, &$totalBackfilled) {
                    foreach ($users as $user) {
                        // Determine JobTitle from user's role or fallback
                        $roleName = $user->role ? $user->role->name : 'Staff';
                        $titleCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $roleName) ?: 'STAFF', 0, 16));

                        $jobTitle = JobTitle::firstOrCreate(
                            ['tenant_id' => $tenant->id, 'title_code' => $titleCode],
                            [
                                'department_id' => $defaultDept->id,
                                'name' => $user->role ? $user->role->display_name ?? $roleName : 'General Staff',
                                'grade_level' => str_contains(strtolower($roleName), 'manager') ? 5 : (str_contains(strtolower($roleName), 'lead') ? 3 : 1),
                                'is_active' => true,
                            ]
                        );

                        // Allocate unique employee number
                        $empNumber = $user->employee_id;
                        if (empty($empNumber) || Employee::where('employee_number', $empNumber)->exists()) {
                            $empNumber = EmployeeIdGenerator::next((int) $tenant->id);
                        }

                        $hireDate = $user->join_date ? $user->join_date->toDateString() : ($user->created_at ? $user->created_at->toDateString() : now()->toDateString());
                        $status = $user->is_active ? EmploymentStatusEnum::ACTIVE : EmploymentStatusEnum::ARCHIVED;

                        if ($isDryRun) {
                            $this->line("    [DRY-RUN] Would create Employee: {$user->name} ({$empNumber}) for User [{$user->id}]");
                            $totalBackfilled++;

                            continue;
                        }

                        $employee = Employee::create([
                            'tenant_id' => $tenant->id,
                            'user_id' => $user->id,
                            'employee_number' => $empNumber,
                            'full_name' => $user->name,
                            'email' => $user->email,
                            'phone_number' => $user->phone_number ?: '080000000000',
                            'gender' => GenderEnum::MALE,
                            'department_id' => $defaultDept->id,
                            'job_title_id' => $jobTitle->id,
                            'employment_type' => EmploymentTypeEnum::PERMANENT,
                            'employment_status' => $status,
                            'hire_date' => $hireDate,
                        ]);

                        // Initialize default tax profile
                        EmployeeTaxProfile::firstOrCreate(
                            ['tenant_id' => $tenant->id, 'employee_id' => $employee->id],
                            [
                                'ptkp_status' => PtkpStatusEnum::TK_0,
                                'calculation_method' => TaxCalculationMethodEnum::GROSS,
                                'is_eligible_for_dtp' => false,
                                'effective_from' => $hireDate,
                            ]
                        );

                        $this->line("    Created Employee: {$employee->full_name} ({$employee->employee_number}) -> User [{$user->id}]");
                        $totalBackfilled++;
                    }
                });
            });
        }

        $this->info("Backfill complete. Total employees processed: {$totalBackfilled}.");

        return self::SUCCESS;
    }
}
