<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Workforce\Enums\CommissionRecordStatusEnum;
use App\Modules\Workforce\Enums\CommissionRecordTypeEnum;
use App\Modules\Workforce\Enums\EmploymentStatusEnum;
use App\Modules\Workforce\Models\CommissionRecord;
use App\Modules\Workforce\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

#[Lazy]
class CommissionManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public ?int $employeeFilter = null;

    public string $statusFilter = 'ALL';

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="space-y-4 p-4 animate-pulse" role="status" aria-label="Loading commission analytics">
            <div class="h-8 bg-muted rounded-xl w-48"></div>
            <div class="h-64 bg-card border border-border rounded-xl"></div>
        </div>
        HTML;
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = CommissionRecord::query()
            ->where('tenant_id', $tenantId)
            ->with(['employee'])
            ->orderBy('id', 'desc');

        if ($this->employeeFilter) {
            $query->where('employee_id', $this->employeeFilter);
        }

        if ($this->statusFilter !== 'ALL') {
            $statusEnum = CommissionRecordStatusEnum::tryFrom($this->statusFilter);
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum);
            }
        }

        $records = $query->paginate(15);

        /** @var Collection<int, Employee> $employees */
        $employees = Employee::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employment_status', [EmploymentStatusEnum::ACTIVE, EmploymentStatusEnum::ON_PROBATION])
            ->orderBy('full_name')
            ->get();

        $totalEarned = (float) CommissionRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('type', CommissionRecordTypeEnum::STANDARD)
            ->sum('commission_amount');

        $totalReversed = (float) CommissionRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('type', CommissionRecordTypeEnum::REVERSAL)
            ->sum('commission_amount');

        $totalRecordsCount = CommissionRecord::query()
            ->where('tenant_id', $tenantId)
            ->count();

        return view('livewire.hr.commission-manager', [
            'records' => $records,
            'employees' => $employees,
            'totalEarned' => $totalEarned,
            'totalReversed' => $totalReversed,
            'netCommission' => $totalEarned - $totalReversed,
            'totalRecordsCount' => $totalRecordsCount,
        ])->layout('layouts.app', ['title' => 'Sales Commission Summary — Apeiron']);
    }
}
