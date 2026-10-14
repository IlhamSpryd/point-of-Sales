<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExportTaskStatus;
use App\Enums\OrderStatus;
use App\Exports\SalesExport;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\ExportTask;
use App\Models\Order;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
}
