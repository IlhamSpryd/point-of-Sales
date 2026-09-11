<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Midtrans\Config;
use Midtrans\Transaction;

class TransactionController extends Controller
{
    public function __construct(protected TransactionService $transactionService) {}

    /**
     * Menampilkan antarmuka Pembuatan Pesanan (Point of Sales)
     */
    public function create(): View
    {
        $title = 'Buat Pesanan';
        
        $products = Product::with('category')->get();
        $categories = Category::all();

        $today = Carbon::today();
        $metricsQuery = Order::whereDate('order_date', $today)
            ->where('order_status', 'paid')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as omzet, COUNT(*) as jumlah')
            ->first();

        $todayOmzet = (float) $metricsQuery->omzet;
        $todayCount = (int) $metricsQuery->jumlah;

        $taxRatePercent = 10;
        $taxRate = $taxRatePercent / 100;
        
        $activePaymentMethods = [
            'cash' => true,
            'qris' => true,
            'ewallet' => true,
        ];
        
        $hardwareAutoDrawer = false;
        $canApplyDiscount = false;
        $roundingBehavior = 'ROUND_NEAREST';
        $roundingValue = 100;

        return view('transaction.create', compact(
            'title', 'products', 'categories', 'todayOmzet', 'todayCount',
            'taxRate', 'taxRatePercent', 'roundingBehavior', 'roundingValue', 
            'activePaymentMethods', 'hardwareAutoDrawer', 'canApplyDiscount'
        ));
    }

    /**
     * Memproses pesanan dari UI Kasir 
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|string',
            'cash_received' => 'nullable|numeric'
        ]);

        $userId = Auth::id() ?? 1;

        try {
            $order = $this->transactionService->createTransaction($validated, $userId);

            // Selalu kembalikan JSON karena POS menggunakan fetch API
            if ($request->wantsJson() || ($validated['payment_method'] ?? 'cash') !== 'cash') {
                return response()->json([
                    'success' => true,
                    'message' => 'Pesanan berhasil dibuat',
                    'snap_token' => $order->snap_token ?? null,
                    'order_number' => $order->order_code,
                ]);
            }

            return redirect()->route('payment.success', ['order_id' => $order->order_code])
                ->with('success', 'Transaksi berhasil ditambahkan');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Transaction Error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Halaman sukses pembayaran — menampilkan konfirmasi dan tombol cetak struk.
     * Identik dengan belajar-laravel/OrderController@paymentSuccess
     */
    public function paymentSuccess(Request $request): View
    {
        $orderId = $request->query('order_id');

        // Sinkronisasi status ke Midtrans (berguna untuk localhost tanpa webhook)
        if ($orderId) {
            $order = Order::where('order_code', $orderId)->first();

            if ($order && $order->order_status === 'pending' && $order->payment_method !== 'cash') {
                Config::$serverKey = config('services.midtrans.server_key');
                Config::$isProduction = config('services.midtrans.is_production');
                Config::$curlOptions = [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [],
                ];

                try {
                    $status = (object) Transaction::status($orderId);

                    if (isset($status->transaction_status) && in_array($status->transaction_status, ['capture', 'settlement'])) {
                        $order->update(['order_status' => 'paid']);
                    }
                } catch (\Exception $e) {
                    // Abaikan error — webhook production akan handle
                }
            }
        }

        return view('transaction.payment-success', [
            'order_id' => $orderId,
        ]);
    }

    /**
     * Cetak struk pesanan — halaman thermal printer.
     * Identik dengan belajar-laravel/OrderController@receipt
     */
    public function receipt(string $orderNumber): View
    {
        $order = Order::with(['orderDetails.product', 'user'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        return view('transaction.receipt', compact('order'));
    }

    /**
     * Sinkronisasi status Midtrans secara manual (untuk localhost tanpa webhook).
     * Identik dengan belajar-laravel/OrderController@syncMidtrans
     */
    public function syncMidtrans(string $orderNumber)
    {
        $order = Order::where('order_code', $orderNumber)->first();

        if ($order && $order->order_status === 'pending' && $order->payment_method !== 'cash') {
            Config::$serverKey = config('services.midtrans.server_key');
            Config::$isProduction = config('services.midtrans.is_production');
            Config::$curlOptions = [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [],
            ];

            try {
                $status = (object) Transaction::status($orderNumber);

                if (isset($status->transaction_status) && in_array($status->transaction_status, ['capture', 'settlement'])) {
                    if ($order->order_status !== 'paid') {
                        $order->update(['order_status' => 'paid']);
                    }
                }
            } catch (\Exception $e) {
                // Biarkan hening, akan di-retry manual
            }
        }

        return response()->json(['success' => true]);
    }
}
