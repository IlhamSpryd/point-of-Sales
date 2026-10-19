<?php

namespace App\Http\Controllers\Customer;

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
                ->with('error', 'Silakan scan ulang QR Code di meja Anda.');
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $table = Table::withoutGlobalScopes()
            ->where('id', $tableId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (! $table) {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Meja tidak ditemukan atau sedang tidak aktif.');
        }

        if ($table->operational_status === 'cleaning') {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Meja sedang dalam proses pembersihan. Silakan panggil staf kami.');
        }

        if ($table->operational_status === 'reserved') {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Meja ini telah direservasi. Silakan hubungi staf untuk konfirmasi kedatangan.');
        }

        $items = $this->cartService->getItems();

        if (empty($items)) {
            return redirect()->route('customer.cart.index')->with('error', 'Keranjang Anda masih kosong.');
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
            return redirect()->route('customer.cart.index')->with('error', 'Sesi meja tidak ditemukan, silakan scan ulang QR Code.');
        }

        // === IDEMPOTENCY GUARD ===
        $idempotencyKey = $request->session()->pull('checkout_idempotency_key');
        if (! $idempotencyKey || $idempotencyKey !== $request->input('_idempotency_key')) {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Pesanan sudah diproses. Jangan klik tombol bayar lebih dari sekali.');
        }

        $items = $this->cartService->getItems();

        if (empty($items)) {
            return redirect()->route('customer.cart.index')->with('error', 'Keranjang Anda masih kosong.');
        }

        if ($this->cartService->getTotalQty() > 40) {
            return redirect()->route('customer.cart.index')->with('error', 'Maksimal 40 item per pesanan. Silakan panggil staf untuk pesanan besar.');
        }

        // PATCH FOR S-13: gunakan Cache::lock untuk idempotensi yang sesungguhnya.
        $lock = Cache::lock('checkout_idempotency_'.$idempotencyKey, 15);
        if (! $lock->get()) {
            return redirect()->route('customer.checkout.create')
                ->with('error', 'Pembayaran sedang diproses, mohon tunggu sebentar.');
        }

        // PATCH FOR SO-04: Implementasi atomic quota
        $tableQuotaLock = Cache::lock('table_quota_'.$tableId, 15);
        if (! $tableQuotaLock->get()) {
            $lock->release();

            return back()->with('error', 'Pesanan Anda sedang diproses. Mohon tunggu.');
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
                return back()->with('error', 'Meja tidak ditemukan atau sedang tidak aktif.');
            }

            if ($table->operational_status === 'cleaning') {
                return back()->with('error', 'Meja sedang dalam proses pembersihan. Silakan panggil staf kami.');
            }

            if ($table->operational_status === 'reserved') {
                return back()->with('error', 'Meja ini telah direservasi. Silakan hubungi staf untuk konfirmasi kedatangan.');
            }

            // PATCH FOR S-05: cap order Pending per sesi dan per meja.
            $mine = session('customer_orders', []);
            $openPending = $mine === [] ? 0 : Order::whereIn('order_code', $mine)->where('order_status', OrderStatus::Pending)->count();
            $tablePending = Order::where('table_id', $tableId)->where('order_status', OrderStatus::Pending)
                ->where('created_at', '>=', now()->subMinutes(30))->count();
            if ($openPending >= 2 || $tablePending >= 5) {
                return back()->with('error', 'Masih ada pesanan yang belum dibayar. Selesaikan atau tunggu kedaluwarsa sebelum memesan lagi.');
            }

            $transactionItems = array_map(function (array $item) use ($tenantId) {
                // SECURITY: Hitung ulang extra_price dari database, jangan percaya
                // nilai yang sudah dihitung di session (bisa dimanipulasi via devtools).
                $modifierIds = collect($item['options'] ?? [])->pluck('modifier_id')->filter()->all();
                $storeId = app(TenantContext::class)->getStoreId();

                $serverExtraPrice = 0;
                if (! empty($modifierIds)) {
                    $modifierQuery = Modifier::whereIn('id', $modifierIds)
                        ->where('is_active', true)
                        ->where('tenant_id', $tenantId);
                    $modifiers = $modifierQuery->get();
                    if ($modifiers->count() !== count($modifierIds)) {
                        throw ValidationException::withMessages([
                            'items' => 'Pilihan varian tidak valid atau milik cabang/tenant lain.',
                        ]);
                    }
                    $serverExtraPrice = $modifiers->sum('extra_price');
                }

                $productQuery = Product::where('id', $item['product_id'])
                    ->where('tenant_id', $tenantId);
                $product = $productQuery->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Produk tidak tersedia di cabang/tenant ini.',
                    ]);
                }

                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => "Produk '{$product->product_name}' telah dinonaktifkan dan tidak dapat dipesan.",
                    ]);
                }

                return [
                    'product_id' => $product->id,
                    'quantity' => $item['qty'],
                    'extra_price' => (float) $serverExtraPrice,
                    'unit_price' => $product->product_price + $serverExtraPrice,
                    'options' => $item['options'],
                    'notes' => $item['notes'] ?? null,
                ];
            }, $items);

            // PATCH FOR S-07: guard system user dengan pesan aman.
            $systemUserId = SystemUserResolver::selfOrder($tenantId)->id;
            if (! $systemUserId) {
                Log::critical('[SELF-ORDER] akun sistem self-order belum di-seed.');

                return back()->with('error', 'Layanan pemesanan mandiri sedang tidak tersedia. Silakan panggil staf.');
            }

            // PATCH FOR M-05: Stale Price Protection.
            $liveSubtotal = 0;
            foreach ($transactionItems as $item) {
                $liveSubtotal += ($item['unit_price'] * $item['quantity']);
            }

            $sessionSubtotal = $this->cartService->getSubtotal();

            if ($request->validated('payment_method') === 'cash' && $liveSubtotal > 500000) {
                return back()->with('error', 'Pesanan tunai maksimal Rp 500.000. Untuk pesanan lebih besar, gunakan pembayaran digital atau pesan di Kasir.');
            }

            if (abs($liveSubtotal - $sessionSubtotal) > 1) {
                $this->cartService->refreshCartPrices();

                return back()->with('error', 'Harga beberapa item telah berubah. Silakan periksa kembali keranjang Anda dan coba lagi.');
            }

            $customerId = null;
            if ($phone = $request->validated('customer_phone')) {
                $customer = Customer::where('phone', $phone)->where('tenant_id', $tenantId)->where('is_active', true)->first();
                if ($customer) {
                    $customerId = $customer->id;
                } else {
                    return back()->with('error', 'Nomor HP tidak terdaftar sebagai member.');
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

            return back()->with('error', 'Terjadi gangguan sistem. Pesanan Anda belum dibuat, silakan coba lagi.');
        } catch (\Throwable $e) {
            // PATCH FOR S-07: JANGAN PERNAH tampilkan $e->getMessage() ke pelanggan.
            Log::error('[SELF-ORDER] checkout gagal', ['table_id' => $tableId, 'error' => $e->getMessage()]);

            return back()->with('error', 'Pesanan gagal diproses. Silakan coba lagi atau panggil staf.');
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
            $prep[$key]++;
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
            return redirect()->route('customer.cart.index')->with('success', 'Keranjang berhasil dipulihkan.');
        }

        return redirect()->route('customer.cart.index')->with('error', 'Tidak ada keranjang sebelumnya untuk dipulihkan.');
    }
}
