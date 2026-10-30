<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\RequiresTenantContext;
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
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $departmentFilter = '';

    public string $statusFilter = 'ALL';

    // Modal Form State
    public bool $isCreateModalOpen = false;

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

    public function openCreateModal(): void
    {
        $this->reset(['employeeNumber', 'fullName', 'email', 'phoneNumber', 'nationalIdNumber', 'departmentId', 'jobTitleId']);
        $this->employeeNumber = 'EMP-'.strtoupper(bin2hex(random_bytes(3)));
        $this->hireDate = now()->toDateString();
        $this->isCreateModalOpen = true;
    }

    public function closeCreateModal(): void
    {
        $this->isCreateModalOpen = false;
    }

    public function saveEmployee(): void
    {
        $this->validate();

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        Employee::create([
            'tenant_id' => $tenantId,
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

        $this->isCreateModalOpen = false;
        session()->flash('success', "Karyawan [{$this->fullName}] berhasil didaftarkan.");
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
            $isActive = $employee->employment_status === EmploymentStatusEnum::ACTIVE;
            $employee->employment_status = $isActive ? EmploymentStatusEnum::TERMINATED : EmploymentStatusEnum::ACTIVE;
            $employee->save();

            session()->flash('success', "Status karyawan [{$employee->full_name}] diperbarui.");
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

        if ($this->statusFilter === 'ACTIVE') {
            $query->where('employment_status', EmploymentStatusEnum::ACTIVE);
        } elseif ($this->statusFilter === 'INACTIVE') {
            $query->where('employment_status', '!=', EmploymentStatusEnum::ACTIVE);
        }

        $employees = $query->paginate(12);

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

        return view('livewire.hr.employee-manager', [
            'employees' => $employees,
            'departments' => $departments,
            'jobTitles' => $jobTitles,
        ])->layout('layouts.app', ['header' => 'Master Tenaga Kerja & Karyawan']);
    }
}
