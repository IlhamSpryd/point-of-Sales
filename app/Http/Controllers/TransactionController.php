<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
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
        $userId = Auth::id();

        $idempotencyKey = $validated['idempotency_key'] ?? null;

        try {
            // Guard against race conditions using Cache::lock
            if ($idempotencyKey) {
                // If it already exists in DB, simply return success (idempotency)
                if (Order::where('idempotency_key', $idempotencyKey)->exists()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Pesanan sudah diproses sebelumnya (Idempotent)',
                        'order_number' => Order::where('idempotency_key', $idempotencyKey)->value('order_code'),
                    ]);
                }

                $lock = \Illuminate\Support\Facades\Cache::lock('order_idempotency_'.$idempotencyKey, 10);
                if (! $lock->get()) {
                    throw new \Illuminate\Contracts\Cache\LockTimeoutException();
                }
            }

            try {
                $order = $this->transactionService->createTransaction($validated, $userId);
            } finally {
                if (isset($lock)) {
                    $lock->release();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat',
                'snap_token' => $order->snap_token ?? null,
                'order_number' => $order->order_code,
            ]);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi serupa sedang diproses, coba lagi.',
            ], 429);
        } catch (\Illuminate\Database\QueryException $e) {
            if (isset($idempotencyKey) && str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'idempotency_key')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pesanan sudah diproses (Idempotent fallback)',
                    'order_number' => Order::where('idempotency_key', $idempotencyKey)->value('order_code'),
                ]);
            }
            Log::error('Transaction Query Error: '.$e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memproses transaksi (DB Error)'], 500);
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
     *
     * [SEC-005 - CRITICAL FIX - AUDIT KEAMANAN]
     * SEBELUM perbaikan ini, method ini melakukan pembacaan (`Order::first()`)
     * dan penulisan (`->update()`) TANPA `lockForUpdate()`/`DB::transaction()`
     * sama sekali, dan HANYA menangani status 'capture'/'settlement' --
     * mengabaikan 'deny'/'cancel'/'expire' sepenuhnya. Ini membuka DUA celah:
     *
     *  1. RACE CONDITION -- frontend (pos-script.blade.php) memanggil endpoint
     *     ini dari callback onSuccess() DAN onClose() Midtrans Snap, yang bisa
     *     terpicu nyaris bersamaan. Tanpa row-lock, dua request paralel bisa
     *     saling menimpa dalam jendela balapan yang sama.
     *  2. STATUS REGRESSION -- karena Transaction::status() adalah panggilan
     *     jaringan ke Midtrans (butuh waktu), webhook resmi
     *     (MidtransNotificationController) bisa saja SUDAH memfinalisasi order
     *     ini (misal jadi 'Failed' via notifikasi 'deny') PERSIS di jendela
     *     waktu tersebut -- lalu endpoint sync ini datang belakangan dan
     *     MENIMPA PAKSA status yang sudah final itu kembali jadi 'Paid'. Ini
     *     adalah kebocoran finansial nyata: pembayaran yang sudah ditolak
     *     Midtrans bisa tercatat lunas di sistem kita.
     *
     * Perbaikan: seluruh logika pembaruan status kini didelegasikan penuh ke
     * TransactionService::updateStatusFromMidtransNotification() -- method
     * yang SAMA PERSIS dipakai webhook resmi, yang sudah membungkus
     * lockForUpdate()+DB::transaction()+idempotency guard (isFinal()) dan
     * menangani SELURUH status Midtrans, bukan hanya jalur bahagia. Controller
     * ini sekarang HANYA bertugas mengambil status terbaru dari Midtrans
     * (network call) lalu meneruskannya -- tidak lagi menulis ke database
     * secara langsung.
     */
    public function syncMidtrans(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_code', $orderNumber)->first();

        if (! $order || $order->order_status !== OrderStatus::Pending || $order->payment_method === \App\Enums\PaymentMethod::Cash) {
            return response()->json(['success' => true]);
        }

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

            // [SEC-006] Sertakan gross_amount yang dilaporkan Midtrans (jika ada)
            // agar TransactionService bisa memverifikasinya terhadap order_amount
            // di database sebelum mengubah status apa pun.
            $grossAmount = isset($status->gross_amount)
                ? (int) round((float) $status->gross_amount)
                : null;

            $this->transactionService->updateStatusFromMidtransNotification(
                orderCode: $orderNumber,
                transactionStatus: (string) ($status->transaction_status ?? ''),
                fraudStatus: $status->fraud_status ?? null,
                grossAmount: $grossAmount,
            );
        } catch (\Exception $e) {
            Log::error('syncMidtrans Error: '.$e->getMessage());
            // Biarkan hening, akan di-retry manual
        }

        return response()->json(['success' => true]);
    }

    /**
     * Endpoint untuk mendapatkan payload raw ESC/POS (QZ Tray).
     */
    public function printPayload(string $orderNumber, \App\Services\Printing\ReceiptPrinterService $printerService): JsonResponse
    {
        $order = Order::with(['orderItems.product', 'user', 'table'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        $payload = $printerService->generatePayload($order);

        return response()->json($payload);
    }
}
