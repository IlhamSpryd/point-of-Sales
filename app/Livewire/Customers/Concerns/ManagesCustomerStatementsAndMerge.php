<?php

declare(strict_types=1);

namespace App\Livewire\Customers\Concerns;

use App\Core\Context\TenantContext;
use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessCustomerStatementExportJob;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Reporting\Models\ExportTask;
use App\Modules\Sales\Services\AccountsReceivableService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

trait ManagesCustomerStatementsAndMerge
{
    public bool $showMergeModal = false;

    public bool $showStatementModal = false;

    public string $statementStartDate = '';

    public string $statementEndDate = '';

    public ?array $statementPreview = null;

    public ?int $statementExportTaskId = null;

    // Merge State
    #[Locked]
    public ?int $mergeSourceId = null;

    public ?int $mergeTargetId = null;

    public string $mergeReason = '';

    // --- Sub-Modal: Customer Merge ---
    public function openMerge(int $sourceId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $source = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($sourceId);

        Gate::authorize('merge', $source);

        $this->mergeSourceId = $source->id;
        $this->mergeTargetId = null;
        $this->mergeReason = '';
        $this->showMergeModal = true;
    }

    public function executeMerge(CustomerService $customerService): void
    {
        $this->validate([
            'mergeTargetId' => 'required|integer',
            'mergeReason' => 'required|string|min:5',
        ]);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $source = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->mergeSourceId);

        $target = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->mergeTargetId);

        Gate::authorize('merge', $source);

        try {
            $customerService->mergeCustomers($source, $target, auth()->user(), $this->mergeReason);
            session()->flash('message', "Customer '{$source->customer_code}' successfully merged into '{$target->customer_code}'.");
            $this->showMergeModal = false;
        } catch (\Throwable $e) {
            session()->flash('merge_error', $e->getMessage());
        }
    }

    public function openStatement(int $customerId): void
    {
        $this->activeCustomerId = $customerId;
        $this->statementStartDate = now()->startOfMonth()->toDateString();
        $this->statementEndDate = now()->toDateString();
        $this->loadStatementPreview();
        $this->showStatementModal = true;
    }

    public function loadStatementPreview(): void
    {
        if (! $this->activeCustomerId) {
            return;
        }

        try {
            $arService = app(AccountsReceivableService::class);
            $start = Carbon::parse($this->statementStartDate);
            $end = Carbon::parse($this->statementEndDate);
            $this->statementPreview = $arService->generateCustomerStatement($this->activeCustomerId, $start, $end);
        } catch (\Throwable $e) {
            $this->statementPreview = null;
            session()->flash('error', 'Unable to load statement: '.$e->getMessage());
        }
    }

    public function exportStatement(): void
    {
        if (! $this->activeCustomerId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $task = ExportTask::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'requested_by' => auth()->id(),
            'type' => 'customer_statement',
            'parameters' => [
                'customer_id' => $this->activeCustomerId,
                'start_date' => $this->statementStartDate,
                'end_date' => $this->statementEndDate,
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        ProcessCustomerStatementExportJob::dispatch($task, $tenantId, $storeId);
        $this->statementExportTaskId = $task->id;
        session()->flash('message', 'Ekspor rekening koran sedang diproses di latar belakang (Task #'.$task->id.').');
    }

    public function closeStatement(): void
    {
        $this->showStatementModal = false;
        $this->statementPreview = null;
    }
}
