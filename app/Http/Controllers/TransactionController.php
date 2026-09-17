<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        $products = Product::where('is_active', true)->with('category')->get();
        $categories = Category::all();

        $taxRate = config('pos.tax_rate', 0.11);
        $taxRatePercent = $taxRate * 100;
        $roundingBehavior = config('pos.rounding_behavior', 'ROUND_NEAREST');
        $roundingValue = config('pos.rounding_value', 100);
        $activePaymentMethods = config('pos.active_payment_methods', [
            'cash' => true,
            'qris' => false,
            'ewallet' => false,
        ]);

        // As per TransactionService implementation
        $hardwareAutoDrawer = false;

        $metrics = $this->transactionService->getTodayMetrics();
        $todayOmzet = $metrics['omzet'];
        $todayCount = $metrics['jumlah'];

        return view('transaction.create', compact(
            'products',
            'categories',
            'taxRate',
            'taxRatePercent',
            'roundingBehavior',
            'roundingValue',
            'activePaymentMethods',
            'hardwareAutoDrawer',
            'todayOmzet',
            'todayCount'
        ));
    }

    /**
     * Memproses pesanan dari UI Kasir
     */
    public function store(StoreTransactionRequest $request)
    {
        $validated = $request->validated();

        // Route ini sudah dilindungi middleware 'auth' dan 'role:Kasir' (lihat routes/web.php),
        // sehingga Auth::id() dijamin tidak pernah null di titik ini. Kita SENGAJA tidak memberi
        // fallback angka statis (misal "?? 1") karena itu akan menyembunyikan bug autentikasi
        // dan mencatat transaksi atas nama user yang salah tanpa disadari (buruk untuk audit trail kasir).
        $userId = Auth::id();

        try {
            $order = $this->transactionService->createTransaction($validated, $userId);

            // Karena UI baru menggunakan popup/modal dinamis di frontend, selalu kembalikan JSON.
            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat',
                'snap_token' => $order->snap_token ?? null,
                'order_number' => $order->order_code,
            ]);
        } catch (\Throwable $e) {
            Log::error('Transaction Error: '.$e->getMessage().' '.$e->getTraceAsString());
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 400);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cetak struk pesanan — halaman thermal printer.
     * Identik dengan belajar-laravel/OrderController@receipt
     */
    public function receipt(string $orderNumber): View
    {
        $order = Order::with(['orderItems.product', 'user', 'table'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        $taxRatePercent = $order->subtotal_amount > 0
            ? round($order->tax_amount / $order->subtotal_amount * 100, 1)
            : 0;

        return view('transaction.receipt', compact('order', 'taxRatePercent'));
    }

    /**
     * Sinkronisasi status Midtrans secara manual (untuk localhost tanpa webhook).
     * Identik dengan belajar-laravel/OrderController@syncMidtrans
     */
    public function syncMidtrans(string $orderNumber)
    {
        $order = Order::where('order_code', $orderNumber)->first();

        if ($order && $order->order_status === OrderStatus::Pending->value && $order->payment_method !== 'cash') {
            Config::$serverKey = config('services.midtrans.server_key');
            Config::$isProduction = config('services.midtrans.is_production', false);
            if (! Config::$isProduction) {
                Config::$curlOptions = [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [],
                ];
            }

            try {
                // Beri waktu 2 detik agar status midtrans di Sandbox benar-benar berubah menjadi settlement,
                // sebelum kita melakukan pengecekan ke server mereka.
                sleep(2);

                $status = (object) Transaction::status($orderNumber);

                if (isset($status->transaction_status) && in_array($status->transaction_status, ['capture', 'settlement'])) {
                    if ($order->order_status !== OrderStatus::Paid->value) {
                        $order->update(['order_status' => OrderStatus::Paid->value]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('syncMidtrans Error: '.$e->getMessage());
                // Biarkan hening, akan di-retry manual
            }
        }

        return response()->json(['success' => true]);
    }
}
