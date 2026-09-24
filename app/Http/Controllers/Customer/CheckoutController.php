<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCheckoutRequest;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
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
        if (! session('current_table_id')) {
            return redirect()->route('customer.menu.index')
                ->with('error', 'Silakan scan ulang QR Code di meja Anda.');
        }

        $items = $this->cartService->getItems();

        if (empty($items)) {
            return redirect()->route('customer.cart.index')->with('error', 'Keranjang Anda masih kosong.');
        }

        $subtotal = $this->cartService->getSubtotal();

        // Generate idempotency key untuk form ini
        $idempotencyKey = Str::uuid()->toString();
        session(['checkout_idempotency_key' => $idempotencyKey]);

        return view('customer.checkout.create', compact('items', 'subtotal', 'idempotencyKey'));
    }

    public function store(StoreCheckoutRequest $request): RedirectResponse
    {
        $tableId = session('current_table_id');

        if (! $tableId) {
            return redirect()->route('customer.menu.index')->with('error', 'Sesi meja tidak ditemukan, silakan scan ulang QR Code.');
        }

        // === IDEMPOTENCY GUARD ===
        // Gunakan token unik per sesi checkout. Jika token sudah dipakai,
        // berarti form ini sudah pernah di-submit → tolak duplikasi.
        $idempotencyKey = $request->session()->pull('checkout_idempotency_key');
        if (! $idempotencyKey || $idempotencyKey !== $request->input('_idempotency_key')) {
            return redirect()->route('customer.cart.index')
                ->with('error', 'Pesanan sudah diproses. Jangan klik tombol bayar lebih dari sekali.');
        }

        $items = $this->cartService->getItems();

        if (empty($items)) {
            return redirect()->route('customer.cart.index')->with('error', 'Keranjang Anda masih kosong.');
        }

        $transactionItems = array_map(function (array $item) {
            // SECURITY: Hitung ulang extra_price dari database, jangan percaya
            // nilai yang sudah dihitung di session (bisa dimanipulasi via devtools).
            $modifierIds = collect($item['options'] ?? [])->pluck('modifier_id')->all();
            $serverExtraPrice = Modifier::whereIn('id', $modifierIds)->sum('extra_price');

            $product = Product::find($item['product_id']);

            return [
                'product_id' => $item['product_id'],
                'quantity' => $item['qty'],
                'extra_price' => (float) $serverExtraPrice,
                'unit_price' => ($product ? $product->product_price : 0) + $serverExtraPrice,
                'options' => $item['options'],
                'notes' => $item['notes'] ?? null,
            ];
        }, $items);

        $systemUserId = User::where('email', config('pos.self_order_system_email'))->value('id');

        if (empty($transactionItems)) {
            return back()->with('error', 'Keranjang belanja Anda kosong.');
        }

        // [OMEGA-NODE9] PATCH FOR M-05: Stale Price Protection.
        // Hitung ulang harga *live* dari database dan pastikan cocok dengan harga di session cart.
        $liveSubtotal = 0;
        foreach ($transactionItems as $item) {
            $liveSubtotal += ($item['unit_price'] * $item['quantity']);
        }

        $sessionSubtotal = $this->cartService->getSubtotal();

        if (abs($liveSubtotal - $sessionSubtotal) > 1) { // Toleransi Rp 1
            // Harga berubah! Paksa update session cart dengan harga live.
            $this->cartService->refreshCartPrices();

            return back()->with('error', 'Harga beberapa item telah berubah. Silakan periksa kembali keranjang Anda dan coba lagi.');
        }

        try {
            $order = $this->transactionService->createTransaction([
                'items' => $transactionItems,
                'payment_method' => $request->validated('payment_method'),
                'cash_received' => null,
                'is_self_order_cash' => $request->validated('payment_method') === 'cash',
                'table_id' => $tableId, // BARU: masukkan ke dalam payload
                'idempotency_key' => $idempotencyKey, // [OMEGA-NODE1] PATCH FOR M-13: Teruskan idempotency key ke layer DB.
            ], $systemUserId);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->cartService->clear();

        return redirect()->route('customer.checkout.success', $order->order_code);
    }

    /**
     * Halaman konfirmasi setelah checkout — memuat Snap Token Midtrans jika
     * metode pembayaran digital (yang menghasilkan snap_token).
     */
    public function success(string $orderCode): View
    {
        $tableId = session('current_table_id');

        $query = Order::where('order_code', $orderCode);

        // Jika ada sesi meja aktif, paksa hanya tampilkan order milik meja itu.
        // Tanpa ini, siapapun bisa menebak order_code dan melihat snap_token orang lain.
        if ($tableId) {
            $query->where('table_id', $tableId);
        }

        $order = $query->firstOrFail();

        // Eager-load relasi table agar view bisa akses $order->table->table_number
        $order->load('table');

        return view('customer.checkout.success', compact('order'));
    }

    /**
     * Polling endpoint untuk melihat status pesanan terbaru dari KDS/Midtrans.
     */
    public function status(string $orderCode)
    {
        $tableId = session('current_table_id');

        $query = Order::where('order_code', $orderCode);

        if ($tableId) {
            $query->where('table_id', $tableId);
        }

        $order = $query->firstOrFail();

        $status = $order->order_status; // sudah instance OrderStatus (cast di model Order)

        return response()->json([
            'order_status' => $status->value,
            'is_paid' => $status === OrderStatus::Paid,
            'is_final' => $status->isFinal(),
        ]);
    }
}
