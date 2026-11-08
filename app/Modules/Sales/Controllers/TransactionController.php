<?php

declare(strict_types=1);

namespace App\Modules\Sales\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\CashierPinService;
use App\Services\DatabaseErrorClassifier;
use App\Services\Printing\KitchenPrinterService;
use App\Services\Printing\ReceiptPrinterService;
use App\Services\ReturnRefundService;
use App\Services\TransactionService;
use App\Support\Traits\RequiresActiveStoreContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Midtrans\Config;
use Midtrans\Transaction;

class TransactionController extends Controller
{
    use RequiresActiveStoreContext;

    public function __construct(protected TransactionService $transactionService) {}

    /**
     * Display the orders history page (Orders Index).
     */
    public function index(): View
    {
        Gate::authorize('viewAny', Order::class);

        return view('orders.index');
    }

    /**
     * Process an order from the Cashier / POS interface.
     */
    public function store(StoreTransactionRequest $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', Order::class);

        $validated = $request->validated();
        $userId = Auth::id();

        $idempotencyKey = $validated['idempotency_key'] ?? null;

        try {
            // Zero-Trust shift guard: every Cashier transaction must be bound
            // to an active open shift to prevent unrecorded ghost sales.
            $validated['shift_id'] = $this->transactionService->resolveOpenShiftOrFail((int) $userId);

            // Guard against race conditions using Cache::lock
            if ($idempotencyKey) {
                // If it already exists in DB, simply return success (idempotency)
                if (Order::where('idempotency_key', $idempotencyKey)->exists()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Order has already been processed (Idempotent)',
                        'order_number' => Order::where('idempotency_key', $idempotencyKey)->value('order_code'),
                    ]);
                }

                $lock = Cache::lock('order_idempotency_'.$idempotencyKey, 10);
                if (! $lock->get()) {
                    throw new LockTimeoutException;
                }
            }

            try {
                $order = $this->transactionService->createTransaction($validated, (int) $userId);
            } finally {
                if (isset($lock)) {
                    $lock->release();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'snap_token' => $order->snap_token ?? null,
                'order_number' => $order->order_code,
            ]);
        } catch (LockTimeoutException $e) {
            return response()->json([
                'success' => false,
                'message' => 'A similar transaction is currently processing. Please try again.',
            ], 429);
        } catch (QueryException $e) {
            $isDuplicate = DatabaseErrorClassifier::isDuplicateEntry($e) || str_contains($e->getMessage(), 'idempotency_key');
            if ($isDuplicate && isset($idempotencyKey) && ($existing = Order::where('idempotency_key', $idempotencyKey)->first())) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order has already been processed (Idempotent fallback)',
                    'order_number' => $existing->order_code,
                ]);
            }
            Log::error('Transaction Query Error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Unable to process transaction (DB Error)'], 500);
        } catch (\Throwable $e) {
            Log::error('Transaction Error: '.$e->getMessage().' '.$e->getTraceAsString());

            // PATCH FOR F-05: Sanitize exception messages
            $safeMessage = $e->getMessage();
            if (! config('app.debug') && ($e instanceof \PDOException || $e instanceof \Error)) {
                $safeMessage = 'Unable to process transaction (Internal Error).';
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
     * Display specific order details.
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
     * Cancel order (Void) through OrderPolicy authorization.
     */
    public function void(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('order_code', $orderNumber)->firstOrFail();

        Gate::authorize('void', $order);

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:255',
            'pin' => 'nullable|string',
        ]);

        // Prevent void if there are pending electronic transactions
        $hasUnresolvedAttempts = PaymentAttempt::where('order_id', $order->id)
            ->whereIn('status', [
                PaymentAttemptStatus::PendingProvider,
                PaymentAttemptStatus::UnknownTimeout,
            ])->exists();

        if ($hasUnresolvedAttempts) {
            return response()->json([
                'success' => false,
                'message' => 'This order has pending electronic payments in verification. Please reconcile the payment before voiding.',
            ], 422);
        }

        if ($order->order_status === OrderStatus::Paid) {
            $user = Auth::user();
            $pin = $request->input('pin');
            if (empty($pin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supervisor PIN is required to authorize void.',
                ], 422);
            }

            try {
                app(CashierPinService::class)->verifySupervisorPin((int) ($user->id ?? 0), (string) $pin, (int) $order->tenant_id);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid supervisor PIN: '.$e->getMessage(),
                ], 422);
            }
        }

        try {
            $this->transactionService->voidOrder($order, $validated['reason'], (int) Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Order successfully voided.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Process order return and refund request.
     */
    public function returnAndRefund(
        Request $request,
        string $orderNumber,
        ReturnRefundService $returnRefundService
    ): JsonResponse {
        $order = Order::where('order_code', $orderNumber)->firstOrFail();
        Gate::authorize('view', $order);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.disposition' => 'required|string|in:restock,quarantine,write_off_waste',
            'items.*.disposition_reason' => 'nullable|string',
            'refunds' => 'required|array|min:1',
            'refunds.*.payment_id' => 'required|integer',
            'refunds.*.amount' => 'required|numeric|min:1',
            'refunds.*.refund_method' => 'nullable|string',
            'refunds.*.is_method_override' => 'nullable|boolean',
            'refunds.*.method_override_reason' => 'nullable|string',
            'supervisor_pin' => 'required|string',
            'reason' => 'required|string|min:5|max:255',
            'shift_id' => 'nullable|integer',
            'device_id' => 'nullable|integer',
        ]);

        $userId = (int) Auth::id();
        $shiftId = $validated['shift_id'] ?? $order->shift_id;
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? 'ret-'.Str::uuid()->toString();

        try {
            $deviceId = $this->resolveActiveDeviceId(
                isset($validated['device_id']) ? (int) $validated['device_id'] : null,
                $order->created_by_device_id ? (int) $order->created_by_device_id : null,
                (int) $order->store_id,
                (int) $order->tenant_id
            );

            $orderReturn = $returnRefundService->createReturnAndRefund(
                $order,
                $validated['items'],
                $validated['refunds'],
                (int) $shiftId,
                $deviceId,
                $userId,
                $validated['supervisor_pin'],
                $validated['reason'],
                $idempotencyKey
            );

            return response()->json([
                'success' => true,
                'message' => 'Return and refund successfully processed.',
                'return_number' => $orderReturn->return_number,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Print order receipt — thermal printer page.
     */
    public function receipt(Request $request, string $orderNumber): View
    {
        $order = Order::with(['orderItems.product', 'orderItems.modifiers', 'user', 'table', 'store', 'customer', 'discount', 'payments', 'device', 'shift', 'paymentRefunds'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        Gate::authorize('view', $order);

        $discountedSubtotal = max(0, (float) $order->subtotal_amount - (float) ($order->discount_amount ?? 0));
        $taxRatePercent = $discountedSubtotal > 0
            ? round($order->tax_amount / $discountedSubtotal * 100, 1)
            : 0;

        $isReprint = $request->boolean('is_reprint') || ((int) $order->print_count > 1);

        return view('transaction.receipt', compact('order', 'taxRatePercent', 'isReprint'));
    }

    /**
     * Manually synchronize Midtrans status (for localhost development without webhooks).
     */
    public function syncMidtrans(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_code', $orderNumber)->firstOrFail();

        Gate::authorize('view', $order);

        if ($order->order_status !== OrderStatus::Pending || $order->payment_method === PaymentMethod::Cash) {
            return response()->json([
                'success' => true,
                'order_status' => $order->order_status->value,
            ]);
        }

        try {
            $order = $this->transactionService->syncOrderWithMidtrans($order);
        } catch (\Throwable $e) {
            Log::error('syncMidtrans Error: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'order_status' => $order->order_status->value,
        ]);
    }

    /**
     * Endpoint to obtain raw ESC/POS payload (QZ Tray).
     */
    public function printPayload(Request $request, string $orderNumber, ReceiptPrinterService $printerService): JsonResponse
    {
        $order = Order::with(['orderItems.product', 'user', 'table'])
            ->where('order_code', $orderNumber)
            ->firstOrFail();

        Gate::authorize('view', $order);

        $actorUserId = (int) Auth::id();
        $reprintReason = $request->input('reprint_reason');
        $supervisorPin = $request->input('supervisor_pin');
        $deviceId = $request->input('device_id') ? (int) $request->input('device_id') : $order->created_by_device_id;
        $shiftId = $request->input('shift_id') ? (int) $request->input('shift_id') : $order->shift_id;

        $payload = $printerService->generatePrintPayload(
            $order,
            $actorUserId,
            $deviceId,
            $shiftId,
            $reprintReason,
            $supervisorPin
        );

        return response()->json($payload);
    }

    /**
     * Endpoint to obtain raw ESC/POS payload (QZ Tray) for kitchen printing.
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
