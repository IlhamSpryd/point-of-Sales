<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSelfOrderRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Table;
use App\Services\TransactionService;

class SelfOrderController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    // Route model binding otomatis resolve $table via secure_token
    // (lihat getRouteKeyName() di Model Table)
    public function menu(Table $table)
    {
        abort_if($table->status !== 'active', 404); // meja nonaktif = 404, bukan error

        $categories = Category::with([
            'products' => fn ($q) => $q->availableForOrder()->with('modifierGroups.modifiers'),
        ])->get();

        return view('self-order.menu', compact('table', 'categories'));
    }

    public function store(StoreSelfOrderRequest $request, Table $table)
    {
        $order = $this->transactionService->createSelfOrder($table, $request->validated()['items']);

        return redirect()
            ->route('self-order.confirmation', $order->id)
            ->with('success', 'Pesanan diterima!');
    }

    public function confirmation(Order $order)
    {
        // View konfirmasi
        return view('self-order.confirmation', compact('order'));
    }
}
