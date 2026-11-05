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
                'reason' => 'Disetujui via HR Portal',
            ]);

            $leaveService->approveLeaveRequest($tenantId, $request->id, $approval->id, (int) Auth::id());
            session()->flash('success', "Permohonan cuti #{$request->id} berhasil disetujui.");
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
            $leaveService->rejectLeaveRequest($tenantId, $request->id, (int) Auth::id(), 'Ditolak oleh manajemen.');
            session()->flash('success', "Permohonan cuti #{$request->id} telah ditolak.");
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
            $query->where('status', LeaveRequestStatusEnum::from($this->statusFilter));
        }

        $requests = $query->paginate(12);

        /** @var Collection<int, LeaveBalance> $balances */
        $balances = LeaveBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['employee', 'policy'])
            ->orderBy('id', 'desc')
            ->take(20)
            ->get();

        return view('livewire.hr.leave-manager', [
            'requests' => $requests,
            'balances' => $balances,
        ])->layout('layouts.app', ['title' => 'Manajemen Cuti & Izin Karyawan — Apeiron']);
    }
}
