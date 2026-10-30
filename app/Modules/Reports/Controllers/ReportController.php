<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Enums\PaymentStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\EodSettlement;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    /**
     * Menampilkan antarmuka Laporan Penjualan (Harian, Mingguan, Bulanan)
     * atau mengekspor laporan jika ada query string export=csv
     */
    public function index(Request $request): View|BinaryFileResponse
    {
        $title = 'Sales Report';

        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Classic Data Recapitulation
        $dailySales = $this->reportService->getSalesSummary($today->copy()->startOfDay(), $today->copy()->endOfDay());
        $weeklySales = $this->reportService->getSalesSummary($startOfWeek, $today);
        $monthlySales = $this->reportService->getSalesSummary($startOfMonth, $today);
        $recentOrders = $this->reportService->getRecentPaidOrders($startOfMonth, $today);

        // Date Range Filter
        $customStart = ($request->filled('start')) ? Carbon::parse($request->start)->startOfDay() : $startOfMonth;
        $customEnd = ($request->filled('end')) ? Carbon::parse($request->end)->endOfDay() : $today->copy()->endOfDay();

        if ($request->filled('start') && $request->filled('end')) {
            $monthlySales = $this->reportService->getSalesSummary($customStart, $customEnd);
            $recentOrders = $this->reportService->getRecentPaidOrders($customStart, $customEnd);
        }

        if ($request->has('export') && $request->export === 'csv') {
            return $this->reportService->exportCsv($customStart, $customEnd);
        }

        // Context Store & Tenant
        $tenantContext = app(TenantContext::class);
        $tenantId = (int) $tenantContext->requireTenantId();
        $storeId = $tenantContext->getStoreId();

        // Detailed Sales Summary for the selected range
        $detailedSummary = $this->reportService->getDetailedSalesSummary($customStart, $customEnd, $storeId);

        // Payment Method Breakdown
        $paymentBreakdown = Payment::where('tenant_id', $tenantId)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->whereBetween('created_at', [$customStart, $customEnd])
            ->whereIn('status', [PaymentStatusEnum::Captured, 'settled'])
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        // EOD Settlement Status for today / current business date
        $businessDate = $today->toDateString();
        $eodSettlement = EodSettlement::with('closedByUser')
            ->where('tenant_id', $tenantId)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->whereDate('business_date', $businessDate)
            ->first();

        return view('reports.sales', compact(
            'title',
            'dailySales',
            'weeklySales',
            'monthlySales',
            'recentOrders',
            'detailedSummary',
            'paymentBreakdown',
            'eodSettlement',
            'businessDate',
            'customStart',
            'customEnd'
        ));
    }

    /**
     * Process End-of-Day (EOD / Z-Report) register closing.
     */
    public function closeEod(Request $request): RedirectResponse
    {
        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Admin'], true)) {
            abort(403, 'Only Owners or Managers are authorized to perform End-of-Day closings.');
        }

        $validated = $request->validate([
            'business_date' => 'required|date_format:Y-m-d',
            'pin' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $tenantContext = app(TenantContext::class);
        $tenantId = (int) $tenantContext->requireTenantId();
        $storeId = $tenantContext->getStoreId() ?? Store::where('tenant_id', $tenantId)->value('id') ?? 1;

        try {
            $eod = $this->reportService->closeEndOfDay(
                $tenantId,
                (int) $storeId,
                $validated['business_date'],
                (int) Auth::id(),
                $validated['pin'],
                $validated['notes'] ?? null
            );

            return back()->with('success', "End-of-Day (Z-Report) for {$validated['business_date']} was successfully closed and locked.");
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return back()->with('error', $message)->withErrors($e->errors());
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to process EOD: '.$e->getMessage());
        }
    }
}
