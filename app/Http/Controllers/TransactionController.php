<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Order;
use App\Services\DatabaseErrorClassifier;
use App\Services\Printing\KitchenPrinterService;
use App\Services\Printing\ReceiptPrinterService;
use App\Services\TransactionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Midtrans\Config;
use Midtrans\Transaction;

class TransactionController extends Controller
{
    public function __construct(protected TransactionService $transactionService) {}

    /**
     * Menampilkan halaman riwayat pesanan (Orders Index).
     */
    public function index(): View
    {
        Gate::authorize('viewAny', Order::class);

        return view('orders.index');
    }

    /**
     * Memproses pesanan dari UI Kasir
     */
    public function store(StoreTransactionRequest $request)
    {
        Gate::authorize('create', Order::class);

        $validated = $request->validated();
        $userId = Auth::id();

        $idempotencyKey = $validated['idempotency_key'] ?? null;

        try {
            // [OMEGA-NODE1] Zero-Trust shift guard: setiap transaksi Kasir
            // WAJIB terikat ke shift yang sedang terbuka -- mencegah
            // "penjualan hantu" yang tidak pernah masuk rekonsiliasi kas
            // per-shift (ShiftManager). Melempar ValidationException jika
            // kasir belum membuka shift, ditangkap oleh catch(\Throwable)
            // di bawah seperti validasi Service lain di controller ini.
            $validated['shift_id'] = $this->transactionService->resolveOpenShiftOrFail((int) $userId);

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

                $lock = Cache::lock('order_idempotency_'.$idempotencyKey, 10);
                if (! $lock->get()) {
                    throw new LockTimeoutException;
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
        } catch (LockTimeoutException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi serupa sedang diproses, coba lagi.',
            ], 429);
        } catch (QueryException $e) {
            $isDuplicate = DatabaseErrorClassifier::isDuplicateEntry($e) || str_contains($e->getMessage(), 'idempotency_key');
            if ($isDuplicate && isset($idempotencyKey) && ($existing = Order::where('idempotency_key', $idempotencyKey)->first())) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pesanan sudah diproses (Idempotent fallback)',
                    'order_number' => $existing->order_code,
                ]);
            }
            Log::error('Transaction Query Error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Gagal memproses transaksi (DB Error)'], 500);
        } catch (\Throwable $e) {
            Log::error('Transaction Error: '.$e->getMessage().' '.$e->getTraceAsString());

            // PATCH FOR F-05: Sanitize exception messages
            $safeMessage = $e->getMessage();
            if (! config('app.debug') && ($e instanceof \PDOException || $e instanceof \Error)) {
                $safeMessage = 'Gagal memproses transaksi (Internal Error).';
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $safeMessage,
                ], 400);
            }

            return back()->with('error', $safeMessage);
        }
    }

    /**
     * Menampilkan detail spesifik pesanan.
     */
    public function show(Request $request, string $orderNumber): JsonResponse|View
    {
        $order = Order::with(['orderItems.product', 'orderItems.modifiers', 'payments', 'customer', 'table', 'user'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        Gate::authorize('view', $order);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'order' => $order,
            ]);
        }

        return view('orders.show', compact('order'));
    }

    /**
     * Membatalkan pesanan (Void) melalui otorisasi OrderPolicy.
     */
    public function void(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('order_code', $orderNumber)->firstOrFail();

        Gate::authorize('void', $order);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $this->transactionService->voidOrder($order, $validated['reason'], (int) Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibatalkan (void).',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cetak struk pesanan — halaman thermal printer.
     */
    public function receipt(string $orderNumber): View
    {
        $order = Order::with(['orderItems.product', 'user', 'table'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        Gate::authorize('view', $order);

        $taxRatePercent = $order->subtotal_amount > 0
            ? round($order->tax_amount / $order->subtotal_amount * 100, 1)
            : 0;

        return view('transaction.receipt', compact('order', 'taxRatePercent'));
    }

    /**
     * Sinkronisasi status Midtrans secara manual (untuk localhost tanpa webhook).
     */
    public function syncMidtrans(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_code', $orderNumber)->firstOrFail();

        Gate::authorize('view', $order);

        if ($order->order_status !== OrderStatus::Pending || $order->payment_method === PaymentMethod::Cash) {
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
            sleep(2);

            $status = (object) Transaction::status($orderNumber);

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
        }

        return response()->json(['success' => true]);
    }

    /**
     * Endpoint untuk mendapatkan payload raw ESC/POS (QZ Tray).
     */
    public function printPayload(string $orderNumber, ReceiptPrinterService $printerService): JsonResponse
    {
        $order = Order::with(['orderItems.product', 'user', 'table'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        Gate::authorize('view', $order);

        $payload = $printerService->generatePayload($order);

        return response()->json($payload);
    }

    /**
     * Endpoint untuk mendapatkan payload raw ESC/POS (QZ Tray) untuk dapur.
     */
    public function printKitchenPayload(string $orderNumber, KitchenPrinterService $printerService): JsonResponse
    {
        $order = Order::with(['orderItems.product', 'table'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        Gate::authorize('view', $order);

        $payload = $printerService->generatePayload($order);

        return response()->json($payload);
    }
}
