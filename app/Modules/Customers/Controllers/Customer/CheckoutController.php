<?php

namespace App\Modules\Customers\Controllers\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCheckoutRequest;
use App\Models\Customer;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\CartService;
use App\Services\CatalogPriceResolverService;
use App\Services\Context\TenantContext;
use App\Services\SystemUserResolver;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * CheckoutController: Jembatan antara keranjang session pelanggan (CartService)
 * dan pencatatan Order permanen di database (TransactionService).
 * Thin Controller — tidak ada logika kalkulasi harga/pajak di sini sama sekali,
 * semua didelegasikan ke Service yang sudah ada.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $cartService,
        protected TransactionService $transactionService,
    ) {}

    /**
     * Menampilkan form checkout (isi nomor meja + pilih metode bayar).
     */
    public function create(): View|RedirectResponse
    {
        // Guard baru: pastikan pelanggan benar-benar sudah scan QR meja
        // sebelum boleh melihat halaman checkout sama sekali.
        $tableId = session('current_table_id');
        if (! $tableId) {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Please rescan the table QR code.');
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $table = Table::withoutGlobalScopes()
            ->where('id', $tableId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (! $table) {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Table not found or currently inactive.');
        }

        if ($table->operational_status === 'cleaning') {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Table is currently being cleaned. Please contact our staff.');
        }

        if ($table->operational_status === 'reserved') {
            return redirect()->route('customer.cart.index')
                ->with('error', 'This table is reserved. Please contact staff to confirm your arrival.');
        }

        $items = $this->cartService->getItems();

        if (empty($items)) {
            return redirect()->route('customer.cart.index')->with('error', 'Your cart is empty.');
        }

        $subtotal = $this->cartService->getSubtotal();
        $totals = $this->transactionService->calculateOrderTotals($subtotal);
        $tax = $totals['tax_amount'];
        $total = $totals['total_amount'];

        // Generate idempotency key untuk form ini
        $idempotencyKey = Str::uuid()->toString();
        session(['checkout_idempotency_key' => $idempotencyKey]);

        $taxRate = (float) Setting::get('tax_rate', config('pos.tax_rate', 0.11));

        return view('customer.checkout.create', compact('items', 'subtotal', 'tax', 'total', 'idempotencyKey', 'taxRate'));
    }

    public function store(StoreCheckoutRequest $request): RedirectResponse
    {
        $tableId = session('current_table_id');

        if (! $tableId) {
            return redirect()->route('customer.cart.index')->with('error', 'Table session not found. Please rescan the table QR code.');
        }

        // === IDEMPOTENCY GUARD ===
        $idempotencyKey = $request->session()->pull('checkout_idempotency_key');
        if (! $idempotencyKey || $idempotencyKey !== $request->input('_idempotency_key')) {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Order is already being processed. Please do not submit multiple times.');
        }

        $items = $this->cartService->getItems();

        if (empty($items)) {
            return redirect()->route('customer.cart.index')->with('error', 'Your cart is empty.');
        }

        if ($this->cartService->getTotalQty() > 40) {
            return redirect()->route('customer.cart.index')->with('error', 'Maximum 40 items per order. Please consult our staff for large orders.');
        }

        // PATCH FOR S-13: gunakan Cache::lock untuk idempotensi yang sesungguhnya.
        $lock = Cache::lock('checkout_idempotency_'.$idempotencyKey, 15);
        if (! $lock->get()) {
            return redirect()->route('customer.checkout.create')
                ->with('error', 'Payment is being processed. Please wait a moment.');
        }

        // PATCH FOR SO-04: Implementasi atomic quota
        $tableQuotaLock = Cache::lock('table_quota_'.$tableId, 15);
        if (! $tableQuotaLock->get()) {
            $lock->release();

            return back()->with('error', 'Your order is currently processing. Please wait.');
        }

        try {
            $tenantId = app(TenantContext::class)->requireTenantId();

            // DOMAIN 1 ACCEPTANCE CRITERIA: Validasi status meja di server pada checkout menggunakan state terbaru database.
            // Mencegah perubahan status antara pemindaian QR dan checkout melewati kebijakan.
            $table = Table::withoutGlobalScopes()
                ->where('id', $tableId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();

            if (! $table) {
                return back()->with('error', 'Table not found or currently inactive.');
            }

            if ($table->operational_status === 'cleaning') {
                return back()->with('error', 'Table is currently being cleaned. Please contact our staff.');
            }

            if ($table->operational_status === 'reserved') {
                return back()->with('error', 'This table is reserved. Please contact staff to confirm your arrival.');
            }

            // PATCH FOR S-05: cap order Pending per sesi dan per meja.
            $mine = session('customer_orders', []);
            $openPending = $mine === [] ? 0 : Order::whereIn('order_code', $mine)->where('order_status', OrderStatus::Pending)->count();
            $tablePending = Order::where('table_id', $tableId)->where('order_status', OrderStatus::Pending)
                ->where('created_at', '>=', now()->subMinutes(30))->count();
            if ($openPending >= 2 || $tablePending >= 5) {
                return back()->with('error', 'You have unpaid pending orders. Please settle or wait until they expire before placing a new order.');
            }

            $transactionItems = array_map(function (array $item) use ($tenantId) {
                // SECURITY: Hitung ulang extra_price dari database, jangan percaya
                // nilai yang sudah dihitung di session (bisa dimanipulasi via devtools).
                /** @var array<int, array<string, mixed>> $rawOptions */
                $rawOptions = is_array($item['options'] ?? null) ? array_values($item['options']) : [];
                $modifierIds = collect($rawOptions)->pluck('modifier_id')->filter()->all();
                $storeId = app(TenantContext::class)->getStoreId();

                $serverExtraPrice = 0;
                if (! empty($modifierIds)) {
                    $modifierQuery = Modifier::whereIn('id', $modifierIds)
                        ->where('is_active', true)
                        ->where('tenant_id', $tenantId);
                    $modifiers = $modifierQuery->get();
                    if ($modifiers->count() !== count($modifierIds)) {
                        throw ValidationException::withMessages([
                            'items' => 'Selected options are invalid or belong to another store.',
                        ]);
                    }
                    $serverExtraPrice = $modifiers->sum('extra_price');
                }

                $productQuery = Product::where('id', $item['product_id'])
                    ->where('tenant_id', $tenantId);
                $product = $productQuery->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Product is unavailable at this store.',
                    ]);
                }

                if (! $product->is_active || $product->status !== 'active') {
                    throw ValidationException::withMessages([
                        'items' => "Product '{$product->product_name}' is inactive and cannot be ordered.",
                    ]);
                }

                $resolver = app(CatalogPriceResolverService::class);
                if (! $resolver->isAvailableInStore($product, $storeId)) {
                    throw ValidationException::withMessages([
                        'items' => "Product '{$product->product_name}' is not available at this store.",
                    ]);
                }

                $resolvedBasePrice = $resolver->resolvePrice($product, $storeId);

                return [
                    'product_id' => $product->id,
                    'quantity' => $item['qty'],
                    'extra_price' => (float) $serverExtraPrice,
                    'unit_price' => $resolvedBasePrice + $serverExtraPrice,
                    'options' => $item['options'],
                    'notes' => $item['notes'] ?? null,
                ];
            }, $items);

            // PATCH FOR S-07: guard system user dengan pesan aman.
            $systemUserId = SystemUserResolver::selfOrder($tenantId)->id;
            if (! $systemUserId) {
                Log::critical('[SELF-ORDER] akun sistem self-order belum di-seed.');

                return back()->with('error', 'Self-ordering service is currently unavailable. Please contact staff.');
            }

            // PATCH FOR M-05: Stale Price Protection.
            $liveSubtotal = 0;
            foreach ($transactionItems as $item) {
                $liveSubtotal += ($item['unit_price'] * $item['quantity']);
            }

            $sessionSubtotal = $this->cartService->getSubtotal();

            if ($request->validated('payment_method') === 'cash' && $liveSubtotal > 500000) {
                return back()->with('error', 'Cash orders are limited to Rp 500,000. For larger amounts, please use digital payment or order at the Cashier.');
            }

            if (abs($liveSubtotal - $sessionSubtotal) > 1) {
                $this->cartService->refreshCartPrices();

                return back()->with('error', 'Prices have updated for some items. Please review your cart and try again.');
            }

            $customerId = null;
            if ($phone = $request->validated('customer_phone')) {
                $customer = Customer::where('phone', $phone)->where('tenant_id', $tenantId)->where('is_active', true)->first();
                if ($customer) {
                    $customerId = $customer->id;
                } else {
                    return back()->with('error', 'Mobile number is not registered as a member.');
                }
            }

            $order = $this->transactionService->createTransaction([
                'items' => $transactionItems,
                'payment_method' => $request->validated('payment_method'),
                'cash_received' => null,
                'is_self_order_cash' => $request->validated('payment_method') === 'cash',
                'table_id' => $tableId,
                'customer_id' => $customerId,
                'idempotency_key' => $idempotencyKey,
            ], $systemUserId);

            // Transisi status meja dari 'available' ke 'occupied' setelah pesanan pertama berhasil dibuat
            if ($table->operational_status === 'available') {
                $table->update(['operational_status' => 'occupied']);
            }
        } catch (ValidationException $e) {
            // Pesan bisnis (stok/varian/harga): aman ditampilkan.
            return back()->with('error', collect($e->errors())->flatten()->first());
        } catch (QueryException $e) {
            // PATCH FOR S-13: double-submit saat order sudah ada, redirect ke success.
            if (str_contains($e->getMessage(), 'idempotency_key')
                && ($existing = Order::where('idempotency_key', $idempotencyKey)->first())) {
                return redirect()->route('customer.checkout.success', $existing->order_code);
            }
            Log::error('[SELF-ORDER] DB error', ['table_id' => $tableId, 'key' => $idempotencyKey, 'error' => $e->getMessage()]);

            return back()->with('error', 'A system issue occurred. Your order has not been placed. Please try again.');
        } catch (\Throwable $e) {
            // PATCH FOR S-07: JANGAN PERNAH tampilkan $e->getMessage() ke pelanggan.
            Log::error('[SELF-ORDER] checkout gagal', ['table_id' => $tableId, 'error' => $e->getMessage()]);

            return back()->with('error', 'Unable to process order. Please try again or ask our staff for assistance.');
        } finally {
            $lock->release();
            $tableQuotaLock->release();
        }

        // PATCH FOR P-09: track order per sesi untuk otorisasi dan riwayat.
        session()->push('customer_orders', $order->order_code);

        // PATCH FOR U-03/P-19: snapshot keranjang sebelum clear.
        $this->cartService->snapshot();
        $this->cartService->clear();

        return redirect()->route('customer.checkout.success', $order->order_code);
    }

    /**
     * Halaman konfirmasi setelah checkout — memuat Snap Token Midtrans jika
     * metode pembayaran digital (yang menghasilkan snap_token).
     */
    public function success(string $orderCode): View
    {
        // PATCH FOR S-09: otorisasi order per SESI, bukan per meja saja.
        abort_unless(in_array($orderCode, session('customer_orders', []), true), 404);

        $tableId = session('current_table_id');

        $query = Order::where('order_code', $orderCode);

        // Lapis kedua: filter per meja jika ada.
        if ($tableId) {
            $query->where('table_id', $tableId);
        }

        $order = $query->firstOrFail();
        $order->load('table');

        return view('customer.checkout.success', compact('order'));
    }

    /**
     * Polling endpoint untuk melihat status pesanan terbaru dari KDS/Midtrans.
     * PATCH FOR S-19: tambah data prep dan hentikan jika sesi hilang.
     */
    public function status(string $orderCode): JsonResponse
    {
        // PATCH FOR S-09: otorisasi per sesi.
        abort_unless(in_array($orderCode, session('customer_orders', []), true), 404);

        $tableId = session('current_table_id');

        $query = Order::where('order_code', $orderCode);

        if ($tableId) {
            $query->where('table_id', $tableId);
        }

        $order = $query->firstOrFail();

        $status = $order->order_status;

        // PATCH FOR S-19/U-05: kirim data preparation_status agar pelanggan bisa lihat progress.
        $order->load('orderItems:id,order_id,preparation_status');
        $prep = ['total' => $order->orderItems->count(), 'pending' => 0, 'brewing' => 0, 'ready' => 0];
        foreach ($order->orderItems as $i) {
            $key = $i->preparation_status->value;
            $prep[$key] = ($prep[$key] ?? 0) + 1;
        }

        // PATCH FOR F-11: ETA Tracking
        $etaPerItem = (int) config('pos.eta_per_item', 3);
        $etaMinutes = ($prep['pending'] * $etaPerItem) + ceil($prep['brewing'] * ($etaPerItem / 2));

        return response()->json([
            'order_status' => $status->value,
            'is_paid' => $status === OrderStatus::Paid,
            'is_final' => $status->isFinal(),
            'prep' => $prep,
            'eta_minutes' => $etaMinutes,
        ]);
    }

    /**
     * PATCH FOR U-03/P-19: Pulihkan keranjang dari snapshot terakhir (pesan ulang).
     */
    public function reorder(): RedirectResponse
    {
        if ($this->cartService->restoreSnapshot()) {
            return redirect()->route('customer.cart.index')->with('success', 'Cart successfully restored.');
        }

        return redirect()->route('customer.cart.index')->with('error', 'No previous cart found to restore.');
    }
}
