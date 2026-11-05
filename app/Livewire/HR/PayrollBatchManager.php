<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Approval;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use App\Modules\HR\Models\PayrollBatch;
use App\Modules\HR\Models\PayrollRecord;
use App\Modules\HR\Services\PayrollBatchService;
use App\Modules\HR\Services\PayrollPostingService;
use App\Services\Context\TenantContext;
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
            session()->flash('success', "Batch penggajian [{$batch->batch_number}] berhasil diinisiasi.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function calculate(int $batchId, PayrollBatchService $batchService): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var PayrollBatch|null $batch */
        $batch = PayrollBatch::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $batchId)
            ->first();

        if (! $batch) {
            return;
        }

        $this->authorize('calculate', $batch);

        try {
            $batchService->executeCalculation($batch);
            session()->flash('success', "Kalkulasi batch penggajian [{$batch->batch_number}] selesai.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function finalize(int $batchId, PayrollBatchService $batchService, PayrollPostingService $postingService): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var PayrollBatch|null $batch */
        $batch = PayrollBatch::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $batchId)
            ->first();

        if (! $batch) {
            return;
        }

        $this->authorize('finalize', $batch);

        try {
            // Buat otorisasi persetujuan
            $approval = Approval::create([
                'tenant_id' => $tenantId,
                'requester_user_id' => (int) Auth::id(),
                'document_type' => 'PAYROLL_BATCH',
                'document_id' => $batch->id,
                'action_code' => 'PAYROLL_EXECUTION',
                'status' => 'APPROVED',
                'approver_user_id' => (int) Auth::id(),
                'approved_at' => now(),
            ]);

            $finalizedBatch = $batchService->finalizeBatch($batch->id, (int) Auth::id(), $approval->id);

            // Teruskan jurnal akrual ke GL
            try {
                $postingService->postAccrualJournal($finalizedBatch, (int) Auth::id());
            } catch (\Throwable) {
                // Jangan batalkan finalisasi jika jurnal GL gagal posting
            }

            session()->flash('success', "Batch penggajian [{$batch->batch_number}] berhasil difinalisasi & dikunci.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
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

        $query = PayrollBatch::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('id', 'desc');

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', PayrollBatchStatusEnum::from($this->statusFilter));
        }

        $batches = $query->paginate(10);

        /** @var Collection<int, PayrollRecord> $records */
        $records = collect();
        if ($this->selectedBatchId) {
            $records = PayrollRecord::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('payroll_batch_id', $this->selectedBatchId)
                ->get();
        }

        return view('livewire.hr.payroll-batch-manager', [
            'batches' => $batches,
            'detailRecords' => $records,
        ])->layout('layouts.app', ['title' => 'Pengelolaan Penggajian (Payroll) — Apeiron']);
    }
}
