<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessAnalyticsExportJob;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\ExportTask;
use App\Models\Store;
use App\Modules\Reports\Services\Analytics\CustomerAnalyticsService;
use App\Modules\Reports\Services\Analytics\FinancialBiService;
use App\Modules\Reports\Services\Analytics\InventoryAnalyticsService;
use App\Modules\Reports\Services\Analytics\SalesAnalyticsService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class AdvancedAnalyticsDashboard extends Component
{
    use RequiresTenantContext;

    #[Url(history: true)]
    public string $activeTab = 'sales';

    #[Url(history: true)]
    public string $datePreset = '30d';

    #[Url(history: true)]
    public string $startDate = '';

    #[Url(history: true)]
    public string $endDate = '';

    #[Url(history: true)]
    public ?int $selectedStoreId = null;

    #[Locked]
    public ?int $activeExportTaskId = null;

    public string $exportFeedback = '';

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! in_array($roleName, ['Owner', 'Manager', 'Admin'], true)) {
            abort(403, 'Access denied: You do not have permission to access the Business Intelligence Dashboard.');
        }
    }

    public function mount(): void
    {
        if (empty($this->startDate) || empty($this->endDate)) {
            $this->applyDatePreset('30d');
        }
    }

    public function updatedDatePreset(string $preset): void
    {
        $this->applyDatePreset($preset);
    }

    public function applyDatePreset(string $preset): void
    {
        $this->datePreset = $preset;
        $now = Carbon::now();

        switch ($preset) {
            case '7d':
                $this->startDate = $now->copy()->subDays(6)->toDateString();
                $this->endDate = $now->toDateString();
                break;
            case '30d':
                $this->startDate = $now->copy()->subDays(29)->toDateString();
                $this->endDate = $now->toDateString();
                break;
            case 'this_month':
                $this->startDate = $now->copy()->startOfMonth()->toDateString();
                $this->endDate = $now->copy()->endOfMonth()->toDateString();
                break;
            case 'last_month':
                $this->startDate = $now->copy()->subMonth()->startOfMonth()->toDateString();
                $this->endDate = $now->copy()->subMonth()->endOfMonth()->toDateString();
                break;
            default:
                // custom: pertahankan tanggal yang dipilih pengguna
                break;
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['sales', 'inventory', 'finance', 'customers'], true)) {
            $this->activeTab = $tab;
            $this->exportFeedback = '';
        }
    }

    /**
     * Dispatch tugas ekspor laporan analitik secara asinkron ke antrean 'exports'.
     */
    public function dispatchExport(string $exportType): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $userId = (int) Auth::id();

        $task = DB::transaction(function () use ($tenantId, $userId, $exportType) {
            return ExportTask::create([
                'tenant_id' => $tenantId,
                'store_id' => $this->selectedStoreId,
                'requested_by' => $userId,
                'type' => $exportType,
                'parameters' => [
                    'start_date' => $this->startDate,
                    'end_date' => $this->endDate,
                    'store_id' => $this->selectedStoreId,
                    'preset' => $this->datePreset,
                ],
                'status' => ExportTaskStatus::Pending,
            ]);
        });

        ProcessAnalyticsExportJob::dispatch($task, $tenantId, $this->selectedStoreId);

        $this->activeExportTaskId = $task->id;
        $this->exportFeedback = "Export task #{$task->id} ({$exportType}) queued successfully.";
    }

    public function checkExportStatus(): ?ExportTask
    {
        if ($this->activeExportTaskId === null) {
            return null;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        /** @var ExportTask|null $task */
        $task = ExportTask::where('tenant_id', $tenantId)
            ->where('id', $this->activeExportTaskId)
            ->first();

        if ($task && $task->status === ExportTaskStatus::Completed) {
            $this->exportFeedback = "Export #{$task->id} is ready for download.";
        }

        return $task;
    }

    public function render(
        SalesAnalyticsService $salesService,
        InventoryAnalyticsService $inventoryService,
        FinancialBiService $financialService,
        CustomerAnalyticsService $customerService
    ): View {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $stores = Store::where('tenant_id', $tenantId)->orderBy('name')->get();

        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);

        $data = [
            'stores' => $stores,
            'activeExportTask' => $this->checkExportStatus(),
        ];

        match ($this->activeTab) {
            'sales' => (function () use (&$data, $salesService, $start, $end) {
                $data['salesTrend'] = $salesService->getSalesTrend($start, $end, 'daily', $this->selectedStoreId);
                $data['topProducts'] = $salesService->getTopProducts($start, $end, $this->selectedStoreId, 10);
                $data['categories'] = $salesService->getCategoryPerformance($start, $end, $this->selectedStoreId);
                $data['momComparison'] = $salesService->getMomComparison($end, $this->selectedStoreId);
                $data['basketAnalysis'] = $salesService->getBasketAnalysis($start, $end, $this->selectedStoreId, 8, 1);
            })(),
            'inventory' => (function () use (&$data, $inventoryService, $start, $end) {
                $data['valuation'] = $inventoryService->getValuationSummary($end, $this->selectedStoreId);
                $data['turnover'] = $inventoryService->getInventoryTurnoverRate($start, $end, $this->selectedStoreId);
                $data['aging'] = $inventoryService->getStockAging($this->selectedStoreId);
                $data['deadStock'] = $inventoryService->getDeadStock(30, $this->selectedStoreId);
            })(),
            'finance' => (function () use (&$data, $financialService, $start, $end) {
                $data['plSummary'] = $financialService->getProfitAndLossSummary($start, $end, $this->selectedStoreId);
            })(),
            'customers' => (function () use (&$data, $customerService, $start, $end) {
                $data['clvSummary'] = $customerService->getCustomerLifetimeValueSummary($this->selectedStoreId, 10);
                $data['rfm'] = $customerService->getRfmSegmentation($this->selectedStoreId);
                $data['loyaltyMovement'] = $customerService->getLoyaltyPointsMovementSummary($start, $end, $this->selectedStoreId);
            })(),
            default => null,
        };

        return view('livewire.reports.advanced-analytics-dashboard', $data);
    }
}
