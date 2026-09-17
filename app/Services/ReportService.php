<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exports\SalesExport;
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
}
