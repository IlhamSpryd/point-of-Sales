<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvestorDemoController extends Controller
{
    /**
     * Tampilkan Hub Visi Produk & Demonstrasi Investor Apeiron System.
     * Mengintegrasikan arsitektur modular ERP dengan F&B dan Retail Industry Packs,
     * status siklus hidup 12 domain, 5 alur kerja demonstrasi, dan matriks keterlacakan 81 PAGE-ID.
     */
    public function index(Request $request): View
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $activeTab = $request->query('tab', 'overview');
        $activeWorkflow = $request->query('workflow', 'workflow-a');

        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        $currentStore = $storeId ? Store::find($storeId) : null;
        $stores = $tenantId ? Store::where('tenant_id', $tenantId)->get() : collect();

        // Metrik riil ringkas untuk membuktikan keterhubungan data
        $realMetrics = [
            'total_stores' => $stores->count(),
            'total_products' => $tenantId ? Product::where('tenant_id', $tenantId)->count() : 0,
            'total_orders' => $storeId ? Order::where('store_id', $storeId)->count() : 0,
            'current_store_name' => $currentStore?->name ?? 'Semua Cabang',
        ];

        return view('investor.index', compact('activeTab', 'activeWorkflow', 'tenant', 'currentStore', 'stores', 'realMetrics'));
    }
}
