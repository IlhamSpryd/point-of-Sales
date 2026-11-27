<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Organization\Models\Approval;
use App\Modules\Organization\Models\Store;
use App\Modules\Workforce\Enums\PayrollBatchStatusEnum;
use App\Modules\Workforce\Models\PayrollBatch;
use App\Modules\Workforce\Models\PayrollRecord;
use App\Modules\Workforce\Services\PayrollBatchService;
use App\Modules\Workforce\Services\PayrollPostingService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class PayrollBatchManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $statusFilter = 'ALL';

    // Modal Create Batch State
    public bool $isCreateModalOpen = false;

    public string $periodStartDate = '';

    public string $periodEndDate = '';

    public string $cutoffDate = '';

    public string $paymentDate = '';

    // Record Detail Modal
    public bool $isDetailModalOpen = false;

    public ?int $selectedBatchId = null;

    public function mount(): void
    {
        $now = now();
        $this->periodStartDate = $now->startOfMonth()->toDateString();
        $this->periodEndDate = $now->endOfMonth()->toDateString();
        $this->cutoffDate = $now->copy()->subDays(5)->toDateString();
        $this->paymentDate = $now->endOfMonth()->toDateString();
    }

    public function openCreateModal(): void
    {
        $this->authorize('create', PayrollBatch::class);

        $now = now();
        $this->periodStartDate = $now->startOfMonth()->toDateString();
        $this->periodEndDate = $now->endOfMonth()->toDateString();
        $this->cutoffDate = $now->copy()->subDays(5)->toDateString();
        $this->paymentDate = $now->endOfMonth()->toDateString();
        $this->isCreateModalOpen = true;
    }

    public function closeCreateModal(): void
    {
        $this->isCreateModalOpen = false;
    }

    public function createBatch(PayrollBatchService $batchService): void
    {
        $this->authorize('create', PayrollBatch::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        try {
            $batch = $batchService->createBatch(
                tenantId: $tenantId,
                periodStart: Carbon::parse($this->periodStartDate),
                periodEnd: Carbon::parse($this->periodEndDate),
                cutoffDate: Carbon::parse($this->cutoffDate),
                paymentDate: Carbon::parse($this->paymentDate),
                createdByUserId: (int) Auth::id()
            );

            $this->isCreateModalOpen = false;
            session()->flash('success', "Payroll batch [{$batch->batch_number}] initiated successfully.");
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('common.alerts.operation_failed'));
        }
    }

    public function calculate(int $batchId, PayrollBatchService $batchService): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var PayrollBatch|null $batch */
        $batch = PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $batchId)
            ->first();

        if (! $batch) {
            return;
        }

        $this->authorize('calculate', $batch);

        try {
            $batchService->executeCalculation($batch);
            session()->flash('success', "Payroll calculation for batch [{$batch->batch_number}] completed.");
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('common.alerts.operation_failed'));
        }
    }

    public function finalize(int $batchId, PayrollBatchService $batchService, PayrollPostingService $postingService): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var PayrollBatch|null $batch */
        $batch = PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $batchId)
            ->first();

        if (! $batch) {
            return;
        }

        $this->authorize('finalize', $batch);

        try {
            $currentUser = Auth::user();
            $currentUserId = (int) Auth::id();
            $creatorUserId = (int) $batch->created_by_user_id;

            // Evaluasi Maker-Checker Guard (Segregation of Duties EXT-04)
            $tenantUsersCount = \App\Modules\Organization\Models\User::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->count();

            $isOwner = $currentUser !== null && (
                (method_exists($currentUser, 'isOwner') && $currentUser->isOwner())
                || (method_exists($currentUser, 'hasRole') && ($currentUser->hasRole('Owner') || $currentUser->hasRole('owner')))
                || ($currentUser->role instanceof \App\Modules\Organization\Models\Role && strtolower((string) $currentUser->role->name) === 'owner')
            );

            $isSoloOwner = $isOwner && $tenantUsersCount <= 1;

            app(\App\Platform\Workflow\MakerCheckerGuard::class)->assertCanApprove(
                $creatorUserId,
                $currentUserId,
                $isSoloOwner
            );

            $storeId = app(TenantContext::class)->getStoreId() ?? $currentUser?->store_id;
            if (! $storeId) {
                $storeId = (int) (Store::where('tenant_id', $tenantId)->value('id') ?? 0);
            }
            if (! $storeId) {
                throw new \DomainException('Cannot finalize payroll batch without a valid store context.');
            }

            \Illuminate\Support\Facades\DB::transaction(function () use (
                $tenantId,
                $storeId,
                $batch,
                $currentUserId,
                $creatorUserId,
                $isSoloOwner,
                $batchService,
                $postingService
            ) {
                if ($isSoloOwner && $creatorUserId === $currentUserId) {
                    app(\App\Platform\Audit\ImmutableAuditWriter::class)->write(
                        tenantId: $tenantId,
                        action: 'workforce.payroll.owner_self_approval_override',
                        userId: $currentUserId,
                        storeId: (int) $storeId,
                        subjectType: PayrollBatch::class,
                        subjectId: $batch->id,
                        description: "Solo-owner self-approval override for payroll batch {$batch->batch_number} by User #{$currentUserId}",
                        payload: [
                            'batch_number' => $batch->batch_number,
                            'solo_owner_override' => true,
                        ]
                    );
                    \Illuminate\Support\Facades\Log::info("[Owner Self-Approval Override] Solo-owner tenant approval for payroll batch {$batch->batch_number} by User #{$currentUserId}");
                }

                // Create authorization approval record dengan requester dan approver terpisah
                $approval = Approval::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'requester_user_id' => $creatorUserId,
                    'approver_user_id' => $currentUserId,
                    'action_code' => 'PAYROLL_EXECUTION',
                    'target_entity_type' => 'PAYROLL_BATCH',
                    'target_entity_id' => $batch->id,
                    'status' => 'APPROVED',
                    'reason' => "Finalization approval for batch {$batch->batch_number}",
                ]);

                $finalizedBatch = $batchService->finalizeBatch($batch->id, $currentUserId, $approval->id);

                // Forward accrual journal to GL secara atomik (tanpa silent catch!)
                $postingService->postAccrualJournal($finalizedBatch, $currentUserId);
            });

            session()->flash('success', "Payroll batch [{$batch->batch_number}] finalized and posted to GL successfully.");
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('common.alerts.operation_failed'));
        }
    }

    public function viewDetails(int $batchId): void
    {
        $this->selectedBatchId = $batchId;
        $this->isDetailModalOpen = true;
    }

    public function closeDetailModal(): void
    {
        $this->isDetailModalOpen = false;
        $this->selectedBatchId = null;
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id', 'desc');

        if ($this->statusFilter !== 'ALL') {
            $statusEnum = PayrollBatchStatusEnum::tryFrom($this->statusFilter);
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum);
            }
        }

        $batches = $query->paginate(10);

        /** @var Collection<int, PayrollRecord> $records */
        $records = collect();
        if ($this->selectedBatchId) {
            $records = PayrollRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('payroll_batch_id', $this->selectedBatchId)
                ->get();
        }

        $totalBatchesCount = PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->count();

        $finalizedBatchesCount = PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [PayrollBatchStatusEnum::FINALIZED, PayrollBatchStatusEnum::PAID])
            ->count();

        $totalGrossDisbursed = (float) PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->sum('total_gross_amount');

        $totalNetDisbursed = (float) PayrollBatch::query()
            ->where('tenant_id', $tenantId)
            ->sum('total_net_amount');

        return view('livewire.hr.payroll-batch-manager', [
            'batches' => $batches,
            'detailRecords' => $records,
            'totalBatchesCount' => $totalBatchesCount,
            'finalizedBatchesCount' => $finalizedBatchesCount,
            'totalGrossDisbursed' => $totalGrossDisbursed,
            'totalNetDisbursed' => $totalNetDisbursed,
        ])->layout('layouts.app', ['title' => 'Payroll Batch Processing — Apeiron']);
    }
}
