<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Enums\ExportTaskStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatusEnum;
use App\Exports\SalesExport;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\EodSettlement;
use App\Models\ExportTask;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\Shift;
use App\Services\AuditTrailService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportService
{
    // FIX (M6-B-002): a crafted ?start=...\&end=... on /reports/sales let
    // any caller of AsyncExportButton request a full-history export,
    // reliably reproducing the memory-exhaustion risk fixed in M6-B-001.
    private const MAX_EXPORT_RANGE_DAYS = 366;

    /**
     * Method ini generik: bisa dipanggil untuk rentang tanggal APAPUN
     * (harian/mingguan/bulanan/custom), cukup ganti parameter $start dan $end.
     */
    public function getSalesSummary(Carbon $start, Carbon $end, ?bool $allStores = null): object
    {
        $isAll = $allStores ?? (
            (auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false)) &&
            (session('active_store_id') === 'all' || session('active_store_id') === null)
        );
        $orderQuery = $isAll ? Order::allStores() : Order::query();

        return (clone $orderQuery)->whereBetween('order_date', [$start, $end])
            ->where('order_status', OrderStatus::Paid->value)
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total, COUNT(*) as count')
            ->first();
    }

    /**
     * Mengambil daftar pesanan berstatus paid terbaru dalam rentang tanggal tertentu.
     *
     * @return LengthAwarePaginator
     */
    public function getRecentPaidOrders(Carbon $start, Carbon $end, int $perPage = 15, ?bool $allStores = null)
    {
        $isAll = $allStores ?? (
            (auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false)) &&
            (session('active_store_id') === 'all' || session('active_store_id') === null)
        );
        $orderQuery = $isAll ? Order::allStores() : Order::query();

        return (clone $orderQuery)->with(['user', 'store'])
            ->whereBetween('order_date', [$start, $end])
            ->where('order_status', OrderStatus::Paid->value)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mengekspor laporan penjualan ke dalam format Excel (.xlsx) rapi.
     *
     * @return BinaryFileResponse
     */
    public function exportCsv(Carbon $start, Carbon $end, ?bool $allStores = null)
    {
        $isAll = $allStores ?? (
            (auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false)) &&
            (session('active_store_id') === 'all' || session('active_store_id') === null)
        );
        $filename = 'Laporan_Penjualan_'.$start->format('Ymd').'-'.$end->format('Ymd').'.xlsx';

        return Excel::download(new SalesExport($start, $end, $isAll), $filename);
    }

    /**
     * Versi ASINKRON exportCsv() untuk rentang besar --
     * exportCsv() (sinkron) SENGAJA TIDAK diubah, UI existing (Node 2)
     * masih memakainya untuk rentang kecil. Lihat SYNC ALERT NODE 2.
     */
    public function queueExport(Carbon $start, Carbon $end, ?int $requestedBy = null, ?bool $allStores = null): ExportTask
    {
        if ($start->diffInDays($end) > self::MAX_EXPORT_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'range' => 'Rentang tanggal ekspor maksimal '.self::MAX_EXPORT_RANGE_DAYS.' hari. Silakan pecah menjadi beberapa ekspor.',
            ]);
        }

        $tenantContext = app(TenantContext::class);
        $resolvedAllStores = $allStores ?? (
            (auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false)) &&
            (session('active_store_id') === 'all' || session('active_store_id') === null)
        );

        $task = ExportTask::create([
            'requested_by' => $requestedBy,
            'type' => 'sales_report',
            'parameters' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'all_stores' => $resolvedAllStores,
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $storeId = $resolvedAllStores ? null : $tenantContext->getStoreId();
        ProcessSalesReportExportJob::dispatch($task, $tenantContext->getTenantId(), $storeId);

        return $task;
    }

    /**
     * Melakukan penutupan buku akhir hari (End-of-Day / Z-Report Closing).
     */
    public function closeEndOfDay(
        int $tenantId,
        int $storeId,
        string $businessDate,
        int $managerUserId,
        string $pin,
        ?string $notes = null
    ): EodSettlement {
        // 1. Verifikasi PIN Manager/Owner
        app(CashierPinService::class)->verifySupervisorPin($managerUserId, $pin, $tenantId);

        return DB::transaction(function () use ($tenantId, $storeId, $businessDate, $managerUserId, $notes) {
            // 2. Cek apakah sudah pernah ditutup untuk tanggal ini
            $alreadyClosed = EodSettlement::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('business_date', $businessDate)
                ->where('status', EodSettlement::STATUS_CLOSED)
                ->exists();

            if ($alreadyClosed) {
                throw ValidationException::withMessages([
                    'eod' => "End-of-Day untuk tanggal {$businessDate} di cabang ini sudah ditutup sebelumnya.",
                ]);
            }

            // 3. Validasi bahwa SELURUH shift di cabang terkait pada tanggal tersebut sudah closed (AC-5.2)
            $openShiftsCount = Shift::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('opened_at', $businessDate)
                ->where('status', '!=', 'closed')
                ->lockForUpdate()
                ->count();

            if ($openShiftsCount > 0) {
                throw ValidationException::withMessages([
                    'eod' => 'Tidak dapat melakukan End-of-Day closing: masih ada shift yang belum ditutup pada tanggal ini.',
                ]);
            }

            // 4. Validasi tidak ada transaksi pending provider / timeout
            $pendingAttemptsCount = PaymentAttempt::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('created_at', $businessDate)
                ->whereIn('status', [PaymentAttemptStatus::UnknownTimeout, PaymentAttemptStatus::PendingProvider])
                ->count();

            if ($pendingAttemptsCount > 0) {
                throw ValidationException::withMessages([
                    'eod' => 'Masih ada transaksi pembayaran yang berstatus pending/timeout. Selesaikan rekonsiliasi sebelum EOD.',
                ]);
            }

            // 5. Agregasi Order & Penjualan
            $paidOrders = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('order_date', $businessDate)
                ->where('order_status', OrderStatus::Paid->value)
                ->get();

            $grossSales = (float) $paidOrders->sum('subtotal_amount');
            $totalDiscounts = (float) $paidOrders->sum('discount_amount');
            $totalTax = (float) $paidOrders->sum('tax_amount');
            $totalServiceCharge = (float) $paidOrders->sum('service_charge_amount');

            $voidedOrders = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('order_date', $businessDate)
                ->where('order_status', OrderStatus::Void->value)
                ->get();
            $totalVoids = (float) $voidedOrders->sum('order_amount');

            // Agregasi Refund & Retur
            $completedRefunds = PaymentRefund::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('created_at', $businessDate)
                ->where('request_status', 'completed')
                ->get();
            $totalReturnsRefunds = (float) $completedRefunds->sum('amount');

            // Net sales = Gross Sales - Total Discounts - Total Returns/Refunds
            $netSales = max(0.00, $grossSales - $totalDiscounts - $totalReturnsRefunds);

            // Agregasi Shift & Kas Laci
            $closedShifts = Shift::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('opened_at', $businessDate)
                ->where('status', 'closed')
                ->get();

            $totalShiftsCount = $closedShifts->count();
            $expectedCashTotal = (float) $closedShifts->sum('expected_cash');
            $countedCashTotal = (float) $closedShifts->sum('closing_balance');
            $cashDiscrepancyTotal = (float) $closedShifts->sum('cash_difference');

            // Payment Breakdown Snapshot
            $payments = Payment::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereDate('created_at', $businessDate)
                ->whereIn('status', [PaymentStatusEnum::Captured, 'settled'])
                ->get();

            $paymentBreakdown = [];
            foreach ($payments->groupBy('payment_method') as $method => $group) {
                $methodStr = is_string($method) ? $method : ($method->value ?? (string) $method);
                $paymentBreakdown[$methodStr] = [
                    'count' => $group->count(),
                    'total' => (float) $group->sum('amount'),
                ];
            }

            // 6. Simpan EOD Settlement
            $eod = EodSettlement::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'business_date' => $businessDate,
                'status' => EodSettlement::STATUS_CLOSED,
                'total_shifts_count' => $totalShiftsCount,
                'gross_sales' => $grossSales,
                'total_discounts' => $totalDiscounts,
                'total_returns_refunds' => $totalReturnsRefunds,
                'total_voids' => $totalVoids,
                'net_sales' => $netSales,
                'total_tax' => $totalTax,
                'total_service_charge' => $totalServiceCharge,
                'expected_cash_total' => $expectedCashTotal,
                'counted_cash_total' => $countedCashTotal,
                'cash_discrepancy_total' => $cashDiscrepancyTotal,
                'payment_breakdown_snapshot' => $paymentBreakdown,
                'closed_by_user_id' => $managerUserId,
                'closed_at' => now(),
                'notes' => $notes,
            ]);

            app(AuditTrailService::class)->log(
                tenantId: $tenantId,
                action: 'store.eod_closed',
                userId: $managerUserId,
                storeId: $storeId,
                subjectType: EodSettlement::class,
                subjectId: $eod->id,
                description: "End-of-Day untuk tanggal {$businessDate} cabang {$storeId} ditutup oleh Manager #{$managerUserId}",
                metadata: [
                    'business_date' => $businessDate,
                    'net_sales' => $netSales,
                    'total_shifts' => $totalShiftsCount,
                    'cash_discrepancy' => $cashDiscrepancyTotal,
                ]
            );

            return $eod;
        });
    }

    /**
     * Mengambil ringkasan detail penjualan untuk EOD dan pelaporan.
     *
     * @return array<string, float|int>
     */
    public function getDetailedSalesSummary(Carbon $start, Carbon $end, ?int $storeId = null): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $orderQuery = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$start, $end]);

        if ($storeId !== null) {
            $orderQuery->where('store_id', $storeId);
        }

        $paidOrders = (clone $orderQuery)->where('order_status', OrderStatus::Paid->value)->get();
        $voidedOrders = (clone $orderQuery)->where('order_status', OrderStatus::Void->value)->get();

        $grossSales = (float) $paidOrders->sum('subtotal_amount');
        $totalDiscounts = (float) $paidOrders->sum('discount_amount');
        $totalTax = (float) $paidOrders->sum('tax_amount');
        $totalServiceCharge = (float) $paidOrders->sum('service_charge_amount');
        $totalVoids = (float) $voidedOrders->sum('order_amount');

        $refundQuery = PaymentRefund::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where('request_status', 'completed');

        if ($storeId !== null) {
            $refundQuery->where('store_id', $storeId);
        }

        $totalReturnsRefunds = (float) $refundQuery->sum('amount');
        $netSales = max(0.00, $grossSales - $totalDiscounts - $totalReturnsRefunds);

        return [
            'gross_sales' => $grossSales,
            'total_discounts' => $totalDiscounts,
            'total_voids' => $totalVoids,
            'total_returns_refunds' => $totalReturnsRefunds,
            'net_sales' => $netSales,
            'total_tax' => $totalTax,
            'total_service_charge' => $totalServiceCharge,
            'paid_orders_count' => $paidOrders->count(),
            'voided_orders_count' => $voidedOrders->count(),
        ];
    }
}
