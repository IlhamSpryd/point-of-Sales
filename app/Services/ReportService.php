<?php

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;

class ReportService
{
    /**
     * Method ini generik: bisa dipanggil untuk rentang tanggal APAPUN
     * (harian/mingguan/bulanan/custom), cukup ganti parameter $start dan $end.
     *
     * @param Carbon $start
     * @param Carbon $end
     * @return object
     */
    public function getSalesSummary(Carbon $start, Carbon $end): object
    {
        return Order::whereBetween('order_date', [$start, $end])
            ->where('order_status', 'paid')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total, COUNT(*) as count')
            ->first();
    }

    /**
     * Mengambil daftar pesanan berstatus paid terbaru dalam rentang tanggal tertentu.
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getRecentPaidOrders(Carbon $start, Carbon $end, int $perPage = 15)
    {
        return Order::with('user')
            ->whereBetween('order_date', [$start, $end])
            ->where('order_status', 'paid')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
