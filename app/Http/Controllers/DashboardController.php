<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * DashboardController: Mengumpulkan ringkasan data statistik sistem (Total Pendapatan,
 * Pesanan, Pelanggan, Produk) untuk dirender pada Beranda Admin (Dashboard).
 */
class DashboardController extends Controller
{
    /**
     * Mengkalkulasi pendapatan dan statistik, lalu merendernya dalam View.
     */
    public function index(): \Illuminate\View\View
    {
        $totalEarnings = Order::where('order_status', 'completed')->sum('order_amount');
        $totalOrders = Order::count();
        $newCustomers = User::count();
        $productsCount = Product::count();

        $recentOrders = Order::with('user')->orderBy('created_at', 'desc')->take(5)->get();

        $topProducts = Product::with('category')->orderBy('stock', 'desc')->take(4)->get();

        return view('dashboard', compact(
            'totalEarnings',
            'totalOrders',
            'newCustomers',
            'productsCount',
            'recentOrders',
            'topProducts'
        ));
    }
}
