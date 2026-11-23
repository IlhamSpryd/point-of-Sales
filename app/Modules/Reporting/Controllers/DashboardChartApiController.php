<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\DashboardDrilldownQueryService;
use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\DashboardOverviewQueryService;
use App\Platform\Authorization\AbilityEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller untuk menyediakan REST API data grafik dan drilldown interaktif ECharts.
 * Mendukung filter mandiri (per-chart), debounce request, dan SLA < 200ms.
 */
class DashboardChartApiController extends Controller
{
    public function __construct(
        protected DashboardOverviewQueryService $overviewQueryService,
        protected DashboardOperationsQueryService $operationsQueryService,
        protected DashboardDrilldownQueryService $drilldownQueryService,
        protected AbilityEvaluator $abilityEvaluator
    ) {}

    public function revenueSeries(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $period = (string) $request->input('period', '30d');
        $granularity = (string) $request->input('granularity', 'daily');
        $user = $request->user();
        $hasMargin = ($user instanceof \App\Modules\Organization\Models\User && $this->abilityEvaluator->can($user, 'reports.view_margins'))
            || (bool) $request->user()?->hasPermission('*')
            || in_array($request->user()?->role?->name, ['Owner', 'Admin', 'Manager'], true);

        $data = $this->overviewQueryService->getRevenueSeries(
            $tenantId,
            $storeId,
            $period,
            $granularity,
            $hasMargin
        );

        return response()->json($data);
    }

    public function paymentVolume(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $period = (string) $request->input('period', '30d');
        $metric = (string) $request->input('metric', 'revenue');

        $data = $this->overviewQueryService->getPaymentMethodBreakdown($tenantId, $storeId, $period, $metric);

        return response()->json($data);
    }

    public function profitabilityCombo(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $period = (string) $request->input('period', '30d');
        $user = $request->user();
        $hasMargin = ($user instanceof \App\Modules\Organization\Models\User && $this->abilityEvaluator->can($user, 'reports.view_margins'))
            || (bool) $request->user()?->hasPermission('*')
            || in_array($request->user()?->role?->name, ['Owner', 'Admin', 'Manager'], true);

        $data = $this->overviewQueryService->getProfitabilityCombo(
            $tenantId,
            $storeId,
            $period,
            $hasMargin
        );

        return response()->json($data);
    }

    public function waterfallBridge(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $period = (string) $request->input('period', '30d');

        $data = $this->overviewQueryService->getRevenueWaterfallBridge($tenantId, $storeId, $period);

        return response()->json($data);
    }

    public function demandHeatmap(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $period = (string) $request->input('period', '30d');
        $metric = (string) $request->input('metric', 'transactions');

        $data = $this->operationsQueryService->getDemandHeatmap($tenantId, $storeId, $period, $metric);

        return response()->json($data);
    }

    public function dateSalesDrilldown(Request $request): JsonResponse
    {
        $request->validate(['date' => 'required|date']);

        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $date = (string) $request->input('date');

        $data = $this->drilldownQueryService->getDateSalesDrilldown($tenantId, $storeId, $date);

        return response()->json($data);
    }

    public function hourlyOrdersDrilldown(Request $request): JsonResponse
    {
        $request->validate([
            'day_of_week' => 'required|integer|min:0|max:6',
            'hour' => 'required|integer|min:0|max:23',
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $dayOfWeek = (int) $request->input('day_of_week');
        $hour = (int) $request->input('hour');
        $period = (string) $request->input('period', '30d');

        $data = $this->drilldownQueryService->getHourlyOrdersDrilldown($tenantId, $storeId, $dayOfWeek, $hour, $period);

        return response()->json($data);
    }

    public function paymentMethodDrilldown(Request $request): JsonResponse
    {
        $request->validate(['method' => 'required|string']);

        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $method = (string) $request->input('method');
        $period = (string) $request->input('period', '30d');

        $data = $this->drilldownQueryService->getPaymentMethodDrilldown($tenantId, $storeId, $method, $period);

        return response()->json($data);
    }

    public function waterfallStepDrilldown(Request $request): JsonResponse
    {
        $request->validate(['step' => 'required|string']);

        $tenantId = (int) $request->user()->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $step = (string) $request->input('step');
        $period = (string) $request->input('period', '30d');

        $data = $this->drilldownQueryService->getWaterfallStepDrilldown($tenantId, $storeId, $step, $period);

        return response()->json($data);
    }
}
