<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\InteractsWithToast;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Concerns\WithBulkActions;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeManager extends Component
{
    use InteractsWithToast;
    use RequiresTenantContext;
    use WithBulkActions;
    use WithPagination;

    public string $search = '';

    public string $departmentFilter = '';

    public string $employmentTypeFilter = '';

    public string $statusFilter = 'ALL';

    // Modal Form State
    public bool $isModalOpen = false;

    public bool $isCreateModalOpen = false; // Kept for backward compatibility

    public string $modalMode = 'create'; // 'create' | 'edit'

    public ?int $editingEmployeeId = null;

    public string $employeeNumber = '';

    public string $fullName = '';

    public string $email = '';

    public string $phoneNumber = '';

    public string $nationalIdNumber = '';

    public string $gender = 'MALE';

    public string $maritalStatus = 'SINGLE';

    public string $religion = 'ISLAM';

    public string $employmentType = 'PERMANENT';

    public ?int $departmentId = null;

    public ?int $jobTitleId = null;

    public string $hireDate = '';

    // System User Account Provisioning State
    public bool $createSystemAccount = false;

    public ?int $systemRoleId = null;

    public string $systemPassword = '';

    public string $cashierPin = '';

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'employeeNumber' => 'required|string|max:32',
            'fullName' => 'required|string|max:150',
            'email' => 'required|email|max:150',
            'phoneNumber' => 'required|string|max:30',
            'nationalIdNumber' => 'required|string|max:30',
            'gender' => 'required|string',
            'maritalStatus' => 'required|string',
            'religion' => 'required|string',
            'employmentType' => 'required|string',
            'departmentId' => 'nullable|integer',
            'jobTitleId' => 'nullable|integer',
            'hireDate' => 'required|date',
        ];
    }

    public function mount(): void
    {
        $this->hireDate = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDepartmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEmploymentTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'departmentFilter', 'employmentTypeFilter', 'statusFilter']);
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorize('create', Employee::class);

        $this->resetForm();
        $this->modalMode = 'create';
        $this->editingEmployeeId = null;
        $this->employeeNumber = 'EMP-'.strtoupper(bin2hex(random_bytes(3)));
        $this->hireDate = now()->toDateString();
        $this->isModalOpen = true;
        $this->isCreateModalOpen = true;
    }

    public function openEditModal(int $employeeId): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var Employee|null $employee */
        $employee = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $employeeId)
            ->firstOrFail();

        $this->authorize('update', $employee);

        $this->editingEmployeeId = $employee->id;
        $this->modalMode = 'edit';
        $this->employeeNumber = $employee->employee_number;
        $this->fullName = $employee->full_name;
        $this->email = $employee->email ?? '';
        $this->phoneNumber = $employee->phone_number ?? '';
        $this->nationalIdNumber = $employee->national_id_number ?? '';
        $this->gender = $employee->gender->value;
        $this->maritalStatus = $employee->marital_status->value;
        $this->religion = $employee->religion->value ?? 'ISLAM';
        $this->employmentType = $employee->employment_type->value;
        $this->departmentId = $employee->department_id;
        $this->jobTitleId = $employee->job_title_id;
        $this->hireDate = $employee->hire_date->toDateString();

        $this->isModalOpen = true;
        $this->isCreateModalOpen = true;
    }

    public function closeModal(): void
    {
        $this->isModalOpen = false;
        $this->isCreateModalOpen = false;
        $this->resetForm();
    }

    public function closeCreateModal(): void
    {
        $this->closeModal();
    }

    private function resetForm(): void
    {
        $this->reset([
            'employeeNumber',
            'fullName',
            'email',
            'phoneNumber',
            'nationalIdNumber',
            'departmentId',
            'jobTitleId',
            'editingEmployeeId',
            'createSystemAccount',
            'systemRoleId',
            'systemPassword',
            'cashierPin',
        ]);
        $this->gender = 'MALE';
        $this->maritalStatus = 'SINGLE';
        $this->religion = 'ISLAM';
        $this->employmentType = 'PERMANENT';
        $this->resetValidation();
    }

    public function saveEmployee(): void
    {
        $this->validate();

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        if ($this->modalMode === 'edit' && $this->editingEmployeeId) {
            /** @var Employee|null $employee */
            $employee = Employee::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->editingEmployeeId)
                ->firstOrFail();

            $this->authorize('update', $employee);

            $employee->update([
                'department_id' => $this->departmentId,
                'job_title_id' => $this->jobTitleId,
                'employee_number' => $this->employeeNumber,
                'full_name' => $this->fullName,
                'email' => $this->email,
                'phone_number' => $this->phoneNumber,
                'national_id_number' => $this->nationalIdNumber,
                'gender' => GenderEnum::from($this->gender),
                'marital_status' => MaritalStatusEnum::from($this->maritalStatus),
                'religion' => ReligionEnum::from($this->religion),
                'employment_type' => EmploymentTypeEnum::from($this->employmentType),
                'hire_date' => $this->hireDate,
            ]);

            $this->closeModal();
            session()->flash('success', "Employee profile [{$this->fullName}] updated successfully.");

            return;
        }

        $this->authorize('create', Employee::class);

        if ($this->createSystemAccount) {
            $this->validate([
                'systemRoleId' => 'required|integer',
                'systemPassword' => 'required|string|min:8',
                'cashierPin' => 'nullable|string|digits_between:4,6',
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId) {
            $userId = null;
            if ($this->createSystemAccount) {
                $user = \App\Models\User::create([
                    'tenant_id' => $tenantId,
                    'name' => $this->fullName,
                    'email' => $this->email,
                    'phone_number' => $this->phoneNumber,
                    'role_id' => $this->systemRoleId,
                    'password' => $this->systemPassword,
                    'cashier_pin' => $this->cashierPin ?: null,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
                $userId = $user->id;

                $defaultStore = \App\Models\Store::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->first();
                if ($defaultStore) {
                    $user->stores()->syncWithoutDetaching([$defaultStore->id => ['tenant_id' => $tenantId]]);
                }
            }

            Employee::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'department_id' => $this->departmentId,
                'job_title_id' => $this->jobTitleId,
                'employee_number' => $this->employeeNumber,
                'full_name' => $this->fullName,
                'email' => $this->email,
                'phone_number' => $this->phoneNumber,
                'national_id_number' => $this->nationalIdNumber,
                'gender' => GenderEnum::from($this->gender),
                'marital_status' => MaritalStatusEnum::from($this->maritalStatus),
                'religion' => ReligionEnum::from($this->religion),
                'employment_type' => EmploymentTypeEnum::from($this->employmentType),
                'employment_status' => EmploymentStatusEnum::ACTIVE,
                'hire_date' => $this->hireDate,
            ]);
        });

        $this->closeModal();
        session()->flash('success', "Employee [{$this->fullName}] registered successfully.");
    }

    public function toggleActiveStatus(int $employeeId): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var Employee|null $employee */
        $employee = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $employeeId)
            ->first();

        if ($employee) {
            $this->authorize('update', $employee);

            $isActive = $employee->employment_status === EmploymentStatusEnum::ACTIVE;
            $employee->employment_status = $isActive ? EmploymentStatusEnum::TERMINATED : EmploymentStatusEnum::ACTIVE;
            $employee->save();

            // Cascade status to linked user account if exists
            if ($employee->user_id) {
                $linkedUser = \App\Models\User::withoutGlobalScopes()->find($employee->user_id);
                if ($linkedUser) {
                    $linkedUser->is_active = ($employee->employment_status === EmploymentStatusEnum::ACTIVE);
                    $linkedUser->save();

                    if (! $linkedUser->is_active) {
                        try {
                            \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $linkedUser->id)->delete();
                        } catch (\Throwable) {}
                    }
                }
            }

            $statusText = $employee->employment_status === EmploymentStatusEnum::ACTIVE ? 'activated' : 'deactivated';
            session()->flash('success', "Employee [{$employee->full_name}] status successfully {$statusText}.");
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['department', 'jobTitle'])
            ->orderBy('id', 'desc');

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('full_name', 'like', "%{$this->search}%")
                    ->orWhere('employee_number', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        if (! empty($this->departmentFilter)) {
            $query->where('department_id', (int) $this->departmentFilter);
        }

        if (! empty($this->employmentTypeFilter)) {
            $query->where('employment_type', $this->employmentTypeFilter);
        }

        if ($this->statusFilter === 'ACTIVE') {
            $query->where('employment_status', EmploymentStatusEnum::ACTIVE);
        } elseif ($this->statusFilter === 'INACTIVE') {
            $query->where('employment_status', '!=', EmploymentStatusEnum::ACTIVE);
        }

        $employees = $query->paginate(10);

        /** @var Collection<int, Department> $departments */
        $departments = Department::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        /** @var Collection<int, JobTitle> $jobTitles */
        $jobTitles = JobTitle::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        $roles = \App\Models\Role::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        $totalEmployees = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->count();

        $activeEmployees = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('employment_status', EmploymentStatusEnum::ACTIVE)
            ->count();

        $contractEmployees = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('employment_type', EmploymentTypeEnum::CONTRACT_PKWT)
            ->count();

        $totalDepartments = Department::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->count();

        return view('livewire.hr.employee-manager', [
            'employees' => $employees,
            'departments' => $departments,
            'jobTitles' => $jobTitles,
            'roles' => $roles,
            'totalEmployees' => $totalEmployees,
            'activeEmployees' => $activeEmployees,
            'contractEmployees' => $contractEmployees,
            'totalDepartments' => $totalDepartments,
        ])->layout('layouts.app', ['title' => 'Employee Directory & Workforce — Apeiron']);
    }
}
