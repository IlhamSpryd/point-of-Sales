<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Approval;
use App\Models\Store;
use App\Modules\HR\Enums\LeaveRequestStatusEnum;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\LeaveService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class LeaveManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $statusFilter = 'ALL';

    public string $activeTab = 'REQUESTS'; // 'REQUESTS' or 'BALANCES'

    public function approve(int $requestId, LeaveService $leaveService): void
    {
        $this->authorize('approve', LeaveRequest::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var LeaveRequest|null $request */
        $request = LeaveRequest::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->first();

        if (! $request) {
            return;
        }

        $this->authorize('approve', $request);

        $primaryBranch = $request->employee?->primaryBranchAssignment;
        $storeId = $primaryBranch ? $primaryBranch->store_id : 0;
        if (! $storeId) {
            $firstStore = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
            $storeId = $firstStore instanceof Store ? $firstStore->id : 1;
        }

        try {
            $approval = Approval::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'requester_user_id' => (int) Auth::id(),
                'approver_user_id' => (int) Auth::id(),
                'action_code' => 'LEAVE_REQUEST',
                'target_entity_type' => 'LEAVE_REQUEST',
                'target_entity_id' => $request->id,
                'status' => 'APPROVED',
                'reason' => 'Approved via HR Portal',
            ]);

            $leaveService->approveLeaveRequest($tenantId, $request->id, $approval->id, (int) Auth::id());
            session()->flash('success', "Leave request #{$request->id} approved successfully.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function reject(int $requestId, LeaveService $leaveService): void
    {
        $this->authorize('reject', LeaveRequest::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var LeaveRequest|null $request */
        $request = LeaveRequest::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->first();

        if (! $request) {
            return;
        }

        $this->authorize('reject', $request);

        try {
            $leaveService->rejectLeaveRequest($tenantId, $request->id, (int) Auth::id(), 'Rejected by management.');
            session()->flash('success', "Leave request #{$request->id} rejected.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = LeaveRequest::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['employee', 'policy'])
            ->orderBy('id', 'desc');

        if ($this->statusFilter !== 'ALL') {
            $statusEnum = LeaveRequestStatusEnum::tryFrom($this->statusFilter);
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum);
            }
        }

        $requests = $query->paginate(12);

        /** @var Collection<int, LeaveBalance> $balances */
        $balances = LeaveBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['employee', 'policy'])
            ->orderBy('id', 'desc')
            ->take(20)
            ->get();

        $pendingCount = LeaveRequest::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', LeaveRequestStatusEnum::PENDING)
            ->count();

        $approvedCount = LeaveRequest::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', LeaveRequestStatusEnum::APPROVED)
            ->count();

        $totalBalanceCount = LeaveBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->count();

        $totalDaysApproved = (float) LeaveRequest::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', LeaveRequestStatusEnum::APPROVED)
            ->sum('total_days');

        return view('livewire.hr.leave-manager', [
            'requests' => $requests,
            'balances' => $balances,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'totalBalanceCount' => $totalBalanceCount,
            'totalDaysApproved' => $totalDaysApproved,
        ])->layout('layouts.app', ['title' => 'Leave & Absence Management — Apeiron']);
    }
}
