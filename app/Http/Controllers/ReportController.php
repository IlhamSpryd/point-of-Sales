<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    /**
     * Menampilkan antarmuka Laporan Penjualan (Harian, Mingguan, Bulanan)
     * atau mengekspor laporan jika ada query string export=csv
     */
    public function index(Request $request)
    {
        $title = 'Laporan Penjualan';

        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Rekapitulasi Data
        $dailySales = $this->reportService->getSalesSummary($today->copy()->startOfDay(), $today->copy()->endOfDay());
        $weeklySales = $this->reportService->getSalesSummary($startOfWeek, $today);
        $monthlySales = $this->reportService->getSalesSummary($startOfMonth, $today);
        $recentOrders = $this->reportService->getRecentPaidOrders($startOfMonth, $today);

        // Titik ekstensi custom date range —
        // jika asesor minta filter tanggal bebas, tinggal tambahkan input form di view yang mengirim
        // ?start=YYYY-MM-DD&end=YYYY-MM-DD, tidak perlu ubah ReportService sama sekali.
        if ($request->filled('start') && $request->filled('end')) {
            $customStart = Carbon::parse($request->start)->startOfDay();
            $customEnd = Carbon::parse($request->end)->endOfDay();

            $monthlySales = $this->reportService->getSalesSummary($customStart, $customEnd);
            $recentOrders = $this->reportService->getRecentPaidOrders($customStart, $customEnd);
        }

        if ($request->has('export') && $request->export === 'csv') {
            // Gunakan rentang custom jika ada, jika tidak gunakan fallback bulan ini
            $exportStart = ($request->filled('start')) ? Carbon::parse($request->start)->startOfDay() : $startOfMonth;
            $exportEnd = ($request->filled('end')) ? Carbon::parse($request->end)->endOfDay() : $today;

            return $this->reportService->exportCsv($exportStart, $exportEnd);
        }

        return view('reports.sales', compact(
            'title', 'dailySales', 'weeklySales', 'monthlySales', 'recentOrders'
        ));
    }
}
