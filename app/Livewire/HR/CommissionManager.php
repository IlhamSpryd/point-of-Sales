<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\HR\Enums\CommissionRecordStatusEnum;
use App\Modules\HR\Enums\CommissionRecordTypeEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Models\CommissionRecord;
use App\Modules\HR\Models\Employee;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class CommissionManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public ?int $employeeFilter = null;

    public string $statusFilter = 'ALL';

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = CommissionRecord::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['employee'])
            ->orderBy('id', 'desc');

        if ($this->employeeFilter) {
            $query->where('employee_id', $this->employeeFilter);
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', CommissionRecordStatusEnum::from($this->statusFilter));
        }

        $records = $query->paginate(15);

        /** @var Collection<int, Employee> $employees */
        $employees = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('employment_status', [EmploymentStatusEnum::ACTIVE, EmploymentStatusEnum::ON_PROBATION])
            ->orderBy('full_name')
            ->get();

        $totalEarned = (float) CommissionRecord::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('type', CommissionRecordTypeEnum::STANDARD)
            ->sum('commission_amount');

        $totalReversed = (float) CommissionRecord::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('type', CommissionRecordTypeEnum::REVERSAL)
            ->sum('commission_amount');

        return view('livewire.hr.commission-manager', [
            'records' => $records,
            'employees' => $employees,
            'totalEarned' => $totalEarned,
            'totalReversed' => $totalReversed,
            'netCommission' => $totalEarned - $totalReversed,
        ])->layout('layouts.app', ['header' => 'Rekapitulasi Komisi Penjualan']);
    }
}
