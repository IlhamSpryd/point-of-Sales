<?php

namespace App\Services;

use App\Enums\ExportTaskStatus;
use App\Enums\OrderStatus;
use App\Exports\SalesExport;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\ExportTask;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportService
{
    /**
     * Method ini generik: bisa dipanggil untuk rentang tanggal APAPUN
     * (harian/mingguan/bulanan/custom), cukup ganti parameter $start dan $end.
     */
    public function getSalesSummary(Carbon $start, Carbon $end): object
    {
        return Order::whereBetween('order_date', [$start, $end])
            ->where('order_status', OrderStatus::Paid->value)
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total, COUNT(*) as count')
            ->first();
    }

    /**
     * Mengambil daftar pesanan berstatus paid terbaru dalam rentang tanggal tertentu.
     *
     * @return LengthAwarePaginator
     */
    public function getRecentPaidOrders(Carbon $start, Carbon $end, int $perPage = 15)
    {
        return Order::with('user')
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
    public function exportCsv(Carbon $start, Carbon $end)
    {
        $filename = 'Laporan_Penjualan_'.$start->format('Ymd').'-'.$end->format('Ymd').'.xlsx';

        return Excel::download(new SalesExport($start, $end), $filename);
    }

    /**
     * [OMEGA-NODE5] Versi ASINKRON exportCsv() untuk rentang besar --
     * exportCsv() (sinkron) SENGAJA TIDAK diubah, UI existing (Node 2)
     * masih memakainya untuk rentang kecil. Lihat SYNC ALERT NODE 2.
     */
    public function queueExport(Carbon $start, Carbon $end, ?int $requestedBy = null): ExportTask
    {
        $task = ExportTask::create([
            'requested_by' => $requestedBy,
            'type' => 'sales_report',
            'parameters' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'status' => ExportTaskStatus::Pending,
        ]);

        ProcessSalesReportExportJob::dispatch($task);

        return $task;
    }
}
