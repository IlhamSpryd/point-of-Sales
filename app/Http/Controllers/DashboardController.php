<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\View\View;

/**
 * DashboardController: Mengumpulkan ringkasan data statistik sistem 
 * untuk dirender pada Beranda Admin (Dashboard) secara real-time.
 */
class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboardService) {}

    /**
     * Mengkalkulasi pendapatan dan statistik, lalu merendernya dalam View.
     */
    public function index(): View
    {
        $metrics = $this->dashboardService->getDashboardMetrics();
        return view('dashboard', $metrics);
    }
}
