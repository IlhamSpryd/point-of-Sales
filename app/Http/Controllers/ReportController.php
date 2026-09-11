<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Menampilkan antarmuka Laporan Penjualan (Harian, Mingguan, Bulanan)
     */
    public function index(Request $request): View
    {
        $title = 'Laporan Penjualan';

        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Rekapitulasi Data
        $dailySales = Order::whereDate('order_date', $today)
            ->where('order_status', 'paid')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total, COUNT(*) as count')
            ->first();

        $weeklySales = Order::whereBetween('order_date', [$startOfWeek, $today])
            ->where('order_status', 'paid')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total, COUNT(*) as count')
            ->first();

        $monthlySales = Order::whereBetween('order_date', [$startOfMonth, $today])
            ->where('order_status', 'paid')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total, COUNT(*) as count')
            ->first();

        // Ambil Data Penjualan Terbaru untuk Ditampilkan di Tabel
        // Filter by tanggal jika diperlukan? Untuk skrg tampilin bulan ini
        $recentOrders = Order::with('user')
            ->whereBetween('order_date', [$startOfMonth, $today])
            ->where('order_status', 'paid')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('reports.sales', compact(
            'title', 'dailySales', 'weeklySales', 'monthlySales', 'recentOrders'
        ));
    }
}
