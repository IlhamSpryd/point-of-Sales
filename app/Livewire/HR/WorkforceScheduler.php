<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Store;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\WorkScheduleStatusEnum;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ShiftTemplate;
use App\Modules\HR\Models\WorkSchedule;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class WorkforceScheduler extends Component
{
    use RequiresTenantContext;

    public ?int $selectedStoreId = null;

    public string $weekStartDate = '';

    // Modal state for assigning schedule
    public bool $isAssignModalOpen = false;

    public ?int $assignEmployeeId = null;

    public string $assignDate = '';

    public ?int $assignShiftTemplateId = null;

    public function mount(): void
    {
        $this->weekStartDate = now()->startOfWeek()->toDateString();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $defaultStore = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $this->selectedStoreId = $defaultStore?->id;
    }

    public function previousWeek(): void
    {
        $this->weekStartDate = Carbon::parse($this->weekStartDate)->subWeek()->startOfWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->weekStartDate = Carbon::parse($this->weekStartDate)->addWeek()->startOfWeek()->toDateString();
    }

    public function openAssignModal(int $employeeId, string $date): void
    {
        $this->assignEmployeeId = $employeeId;
        $this->assignDate = $date;
        $this->assignShiftTemplateId = null;
        $this->isAssignModalOpen = true;
    }

    public function closeAssignModal(): void
    {
        $this->isAssignModalOpen = false;
    }

    public function saveScheduleAssignment(): void
    {
        if (! $this->assignEmployeeId || ! $this->assignShiftTemplateId || ! $this->selectedStoreId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var ShiftTemplate|null $template */
        $template = ShiftTemplate::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $this->assignShiftTemplateId)
            ->first();

        if (! $template) {
            return;
        }

        WorkSchedule::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'employee_id' => $this->assignEmployeeId,
                'schedule_date' => $this->assignDate,
            ],
            [
                'store_id' => $this->selectedStoreId,
                'shift_template_id' => $template->id,
                'scheduled_start_time' => "{$this->assignDate} {$template->start_time}",
                'scheduled_end_time' => "{$this->assignDate} {$template->end_time}",
                'status' => WorkScheduleStatusEnum::SCHEDULED,
            ]
        );

        $this->isAssignModalOpen = false;
        session()->flash('success', 'Shift schedule updated successfully.');
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var Collection<int, Store> $stores */
        $stores = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get();

        $start = Carbon::parse($this->weekStartDate)->startOfWeek();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->copy()->addDays($i);
        }

        /** @var Collection<int, Employee> $employees */
        $employees = Employee::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('employment_status', [EmploymentStatusEnum::ACTIVE, EmploymentStatusEnum::ON_PROBATION])
            ->orderBy('full_name')
            ->get();

        $endDate = $days[6]->toDateString();

        /** @var Collection<int, WorkSchedule> $schedules */
        $schedules = WorkSchedule::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereBetween('schedule_date', [$this->weekStartDate, $endDate])
            ->with(['shiftTemplate'])
            ->get();

        /** @var Collection<int, ShiftTemplate> $shiftTemplates */
        $shiftTemplates = ShiftTemplate::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        return view('livewire.hr.workforce-scheduler', [
            'stores' => $stores,
            'days' => $days,
            'employees' => $employees,
            'schedules' => $schedules,
            'shiftTemplates' => $shiftTemplates,
            'totalScheduledEmployees' => $employees->count(),
            'totalAssignedShifts' => $schedules->count(),
            'totalShiftTemplates' => $shiftTemplates->count(),
            'totalStores' => $stores->count(),
        ])->layout('layouts.app', ['title' => 'Workforce Rostering & Shift Scheduling — Apeiron']);
    }
}
