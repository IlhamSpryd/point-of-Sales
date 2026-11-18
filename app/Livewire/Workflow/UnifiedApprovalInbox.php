<?php

declare(strict_types=1);

namespace App\Livewire\Workflow;

use App\Core\Context\TenantContext;
use App\Enums\ApprovalStatusEnum;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Platform\Audit\ImmutableAuditWriter;
use App\Platform\Workflow\Exceptions\SegregationOfDutiesViolationException;
use App\Platform\Workflow\MakerCheckerGuard;
use App\Platform\Workflow\Services\ApprovalEngineService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Enterprise Unified Approval Inbox Component (EXT-04, ADR-110).
 * Enforces Segregation of Duties (SoD) and append-only audit trail.
 */
class UnifiedApprovalInbox extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $statusFilter = 'PENDING';

    public string $search = '';

    public ?int $activeRejectingId = null;

    public string $rejectionReason = '';

    public bool $isDetailModalOpen = false;

    public ?int $selectedRequestId = null;

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = strtoupper($status);
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function approve(
        int $requestId,
        MakerCheckerGuard $guard,
        ApprovalEngineService $approvalEngine,
        ImmutableAuditWriter $auditWriter
    ): void {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        if (! $user) {
            session()->flash('error', 'Sesi autentikasi tidak valid.');

            return;
        }

        /** @var PurchaseApprovalRequest|null $request */
        $request = PurchaseApprovalRequest::where('tenant_id', $tenantId)->find($requestId);

        if (! $request) {
            session()->flash('error', "Permintaan persetujuan #{$requestId} tidak ditemukan.");

            return;
        }

        try {
            // 1. Maker-Checker SoD Invariant Enforcement
            $guard->assertCanApprove((int) $request->submitted_by_user_id, (int) $user->id);

            // 2. Delegate Approval Execution to Domain Service
            $approvalEngine->approve($request, $user);

            // 3. Immutable Append-Only Audit Logging
            $auditWriter->write(
                tenantId: $tenantId,
                action: 'workflow.approval.approved',
                userId: (int) $user->id,
                storeId: null,
                subjectType: $request->approvable_type,
                subjectId: (int) $request->approvable_id,
                description: "Permintaan persetujuan #{$requestId} ({$request->approvable_type} #{$request->approvable_id}) berhasil disetujui.",
                payload: [
                    'approval_request_id' => $requestId,
                    'step_order' => $request->step_order,
                ]
            );

            session()->flash('message', "Permintaan persetujuan #{$requestId} berhasil disetujui.");
        } catch (SegregationOfDutiesViolationException $e) {
            session()->flash('error', $e->getMessage());
        } catch (DomainException $e) {
            session()->flash('error', 'Gagal memproses persetujuan: '.$e->getMessage());
        } catch (\Throwable $e) {
            session()->flash('error', 'Terjadi kesalahan sistem saat menyetujui dokumen.');
        }
    }

    public function openRejectModal(int $requestId): void
    {
        $this->activeRejectingId = $requestId;
        $this->rejectionReason = '';
    }

    public function closeRejectModal(): void
    {
        $this->activeRejectingId = null;
        $this->rejectionReason = '';
    }

    public function confirmReject(
        MakerCheckerGuard $guard,
        ApprovalEngineService $approvalEngine,
        ImmutableAuditWriter $auditWriter
    ): void {
        $this->validate([
            'rejectionReason' => 'required|string|min:3|max:500',
        ]);

        if (! $this->activeRejectingId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        if (! $user) {
            session()->flash('error', 'Sesi autentikasi tidak valid.');

            return;
        }

        /** @var PurchaseApprovalRequest|null $request */
        $request = PurchaseApprovalRequest::where('tenant_id', $tenantId)->find($this->activeRejectingId);

        if (! $request) {
            session()->flash('error', 'Permintaan persetujuan tidak ditemukan.');
            $this->closeRejectModal();

            return;
        }

        try {
            // 1. Maker-Checker SoD Invariant Enforcement
            $guard->assertCanApprove((int) $request->submitted_by_user_id, (int) $user->id);

            // 2. Delegate Rejection Execution to Domain Service
            $approvalEngine->reject($request, $user, $this->rejectionReason);

            // 3. Immutable Append-Only Audit Logging
            $auditWriter->write(
                tenantId: $tenantId,
                action: 'workflow.approval.rejected',
                userId: (int) $user->id,
                storeId: null,
                subjectType: $request->approvable_type,
                subjectId: (int) $request->approvable_id,
                description: "Permintaan persetujuan #{$this->activeRejectingId} ditolak. Alasan: {$this->rejectionReason}",
                payload: [
                    'approval_request_id' => $this->activeRejectingId,
                    'reason' => $this->rejectionReason,
                ]
            );

            session()->flash('message', "Permintaan persetujuan #{$this->activeRejectingId} berhasil ditolak.");
            $this->closeRejectModal();
        } catch (SegregationOfDutiesViolationException $e) {
            session()->flash('error', $e->getMessage());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (DomainException $e) {
            session()->flash('error', 'Gagal memproses penolakan: '.$e->getMessage());
        } catch (\Throwable $e) {
            session()->flash('error', 'Terjadi kesalahan sistem saat menolak dokumen.');
        }
    }

    public function openDetail(int $requestId): void
    {
        $this->selectedRequestId = $requestId;
        $this->isDetailModalOpen = true;
    }

    public function closeDetailModal(): void
    {
        $this->selectedRequestId = null;
        $this->isDetailModalOpen = false;
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();

        $query = PurchaseApprovalRequest::with(['rule', 'submittedByUser', 'approvedByUser'])
            ->where('tenant_id', $tenantId);

        if ($this->statusFilter !== 'ALL') {
            $enumVal = match ($this->statusFilter) {
                'PENDING' => ApprovalStatusEnum::Pending,
                'APPROVED' => ApprovalStatusEnum::Approved,
                'REJECTED' => ApprovalStatusEnum::Rejected,
                default => null,
            };

            if ($enumVal !== null) {
                $query->where('status', $enumVal);
            }
        }

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('id', 'like', "%{$s}%")
                    ->orWhere('approvable_type', 'like', "%{$s}%")
                    ->orWhereHas('submittedByUser', fn ($sub) => $sub->where('name', 'like', "%{$s}%"));
            });
        }

        $requests = $query->orderByDesc('id')->paginate(15);

        $selectedRequest = null;
        if ($this->isDetailModalOpen && $this->selectedRequestId) {
            $selectedRequest = PurchaseApprovalRequest::with(['logs.actor', 'submittedByUser', 'approvedByUser', 'rule'])
                ->where('tenant_id', $tenantId)
                ->find($this->selectedRequestId);
        }

        return view('livewire.workflow.unified-approval-inbox', [
            'requests' => $requests,
            'selectedRequest' => $selectedRequest,
        ]);
    }
}
