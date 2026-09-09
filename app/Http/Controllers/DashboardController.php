<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $totalEarnings = Order::where('payment_status', 1)->sum('total_price');
        $totalOrders = Order::count();
        $newCustomers = User::count();
        $productsCount = Product::count();

        // Load recent 5 orders explicitly eagerly loading user
        $recentOrders = Order::with('user')->orderBy('created_at', 'desc')->take(5)->get();

        // Load products mapped by some criteria (using creation date here)
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
