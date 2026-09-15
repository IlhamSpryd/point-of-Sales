<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Enums\OrderStatus;
use Midtrans\Config;
use Midtrans\Snap;

class TransactionService
{
    /**
     * Memproses pesanan baru ke sistem database.
     * Disinkronkan dari belajar-laravel/OrderService agar alur identik.
     */
    public function createTransaction(array $data, int $userId): Order
    {
        return DB::transaction(function () use ($data, $userId) {
            $subtotalAmount = 0;
            $lines = [];
            $items = $data['items'] ?? [];

            // Merge items yang sama agar stok tidak double-decrement
            $mergedItems = collect($items)
                ->groupBy('product_id')
                ->map(fn ($group) => [
                    'product_id' => $group->first()['product_id'],
                    'quantity' => $group->sum('quantity'),
                ])
                ->values()
                ->all();

            $productIds = collect($mergedItems)->pluck('product_id')->toArray();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($mergedItems as $item) {
                $product = $products->find($item['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => 'Salah satu produk yang dipilih sudah tidak tersedia.',
                    ]);
                }

                // GUARD TERAKHIR: Baris produk ini sudah di-lock (lockForUpdate) di atas,
                // jadi ini titik paling aman untuk mengecek status aktif. Pengecekan ini
                // menutup celah race condition — kasus di mana Admin menonaktifkan produk
                // PERSIS saat kasir sedang menekan tombol bayar (setelah lolos validasi
                // Form Request, tapi sebelum stok benar-benar dipotong).
                if (!$product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => 'Produk "' . $product->product_name . '" sudah dinonaktifkan dan tidak dapat dijual.',
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => 'Stok produk "' . $product->product_name . '" tidak mencukupi. (Sisa: ' . $product->stock . ')',
                    ]);
                }

                $itemSubtotal = $product->product_price * $item['quantity'];
                $subtotalAmount += $itemSubtotal;

                $lines[] = [
                    'order_price' => $product->product_price,
                    'qty' => $item['quantity'],
                    'order_subtotal' => $itemSubtotal,
                    'product_id' => $product->id,
                ];

                $product->decrement('stock', $item['quantity']);
            }

            ['tax_amount' => $taxAmount, 'total_amount' => $totalAmount] = $this->calculateOrderTotals($subtotalAmount);

            $paymentMethod = $data['payment_method'] ?? 'cash';
            $cashReceived = $data['cash_received'] ?? null;
            $orderChange = 0;

            if ($paymentMethod === 'cash' && $cashReceived) {
                $orderChange = max(0, (int) $cashReceived - $totalAmount);
            }

            // Status awal: cash = paid, non-cash = pending (menunggu konfirmasi Midtrans)
            $initialStatus = $paymentMethod === 'cash' ? OrderStatus::Paid->value : OrderStatus::Pending->value;

            // Generate order code unik
            do {
                $orderCode = 'POS-' . strtoupper(Str::random(6)) . '-' . rand(100, 999);
            } while (Order::where('order_code', $orderCode)->exists());

            // Validasi keamanan sisi server: pastikan uang tunai cukup untuk total tagihan.
            // Pengecekan di frontend (Alpine.js, pos-script.blade.php) bisa dilewati
            // dengan mengirim request langsung ke endpoint transaction.store, sehingga
            // validasi ini WAJIB diulang di sini sebagai sumber kebenaran terakhir.
            if ($paymentMethod === 'cash' && (int) $cashReceived < $totalAmount) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Uang tunai yang diterima tidak mencukupi total tagihan.',
                ]);
            }

            // Buat record Order dengan semua field lengkap
            $order = Order::create([
                'user_id' => $userId,
                'order_code' => $orderCode,
                'order_date' => now()->toDateString(),
                'subtotal_amount' => $subtotalAmount,
                'tax_amount' => $taxAmount,
                'order_amount' => $totalAmount,
                'cash_received' => $paymentMethod === 'cash' ? ($cashReceived ?? $totalAmount) : null,
                'order_change' => $orderChange,
                'order_status' => $initialStatus,
                'payment_method' => $paymentMethod,
            ]);

            // Buat order details
            foreach ($lines as $line) {
                OrderDetail::create(array_merge($line, ['order_id' => $order->id]));
            }

            // Jika pembayaran non-tunai, generate Snap Token Midtrans
            if ($paymentMethod !== 'cash') {
                Config::$serverKey = config('services.midtrans.server_key');
                Config::$isProduction = config('services.midtrans.is_production', false);
                Config::$isSanitized = config('services.midtrans.is_sanitized', true);
                Config::$is3ds = config('services.midtrans.is_3ds', true);

                // Fix SSL + PHP 8 bug pada Midtrans SDK (hanya untuk sandbox)
                if (!config('services.midtrans.is_production', false)) {
                    Config::$curlOptions = [
                        CURLOPT_SSL_VERIFYHOST => 0,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_HTTPHEADER => [],
                    ];
                }

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->order_code,
                        'gross_amount' => (int) $totalAmount,
                    ],
                    'item_details' => [
                        [
                            'id' => 'ORDER-' . $order->id,
                            'price' => (int) $subtotalAmount,
                            'quantity' => 1,
                            'name' => 'Pesanan POS',
                        ],
                        [
                            'id' => 'TAX-PPN',
                            'price' => (int) $taxAmount,
                            'quantity' => 1,
                            'name' => 'Pajak',
                        ],
                    ],
                    'customer_details' => [
                        'first_name' => 'Pelanggan Walk-in',
                    ],
                    'expiry' => [
                        'unit' => 'minutes',
                        'duration' => 30,
                    ],
                ];

                // Mapping enabled_payments sesuai belajar-laravel
                $enabledPayments = match ($paymentMethod) {
                    'qris' => ['other_qris', 'gopay'],
                    'ewallet' => ['gopay', 'shopeepay'],
                    // Opsi metode pembayaran ini dihapus karena tidak pernah diaktifkan lewat
                    // $activePaymentMethods (TransactionController::create()) — menyederhanakan
                    // kode agar sesuai cakupan kebutuhan UjiKom.
                    // TODO: aktifkan setelah $activePaymentMethods mendukung.
                    default => []
                };

                if (!empty($enabledPayments)) {
                    $params['enabled_payments'] = $enabledPayments;
                }

                try {
                    $snapToken = Snap::getSnapToken($params);
                    $order->update(['snap_token' => $snapToken]);
                } catch (\Exception $e) {
                    throw new \Exception("Gagal mendapatkan Snap Token Midtrans: " . $e->getMessage());
                }
            }

            return $order;
        });
    }

    /**
     * Method ini adalah SATU-SATUNYA tempat menghitung total transaksi.
     *
     * // TODO (Titik Ekstensi Ujian): Jika asesor meminta fitur diskon,
     * // tambahkan parameter float $discountPercent = 0 di sini, kurangi
     * // $subtotalAmount SEBELUM menghitung pajak, lalu update pemanggilnya.
     */
    private function calculateOrderTotals(int $subtotalAmount): array
    {
        // Pajak 10%
        // Dipindahkan ke config/pos.php agar tarif pajak & aturan pembulatan tidak
        // terduplikasi dan berisiko tidak sinkron antara Controller dan Service.
        $taxRate = config('pos.tax_rate', 0.11);
        $taxAmount = (int) round($subtotalAmount * $taxRate);
        $totalAmount = (int) ($subtotalAmount + $taxAmount);

        // Pembulatan WAJIB dilakukan di backend, bukan hanya di Alpine.js,
        // agar order_amount yang tersimpan sama persis dengan nominal yang
        // disepakati kasir & pelanggan di layar (single source of truth).
        // PERHATIAN: Desinkronisasi pengaturan pembulatan di sini bisa membuat 
        // kembalian yang diucapkan kasir ke pelanggan berbeda dari nominal di struk cetak.
        $roundingValue = config('pos.rounding_value', 100);
        $roundingBehavior = config('pos.rounding_behavior', 'ROUND_NEAREST');

        $totalAmount = (int) match ($roundingBehavior) {
            'ROUND_NEAREST' => round($totalAmount / $roundingValue) * $roundingValue,
            'ROUND_UP' => ceil($totalAmount / $roundingValue) * $roundingValue,
            'ROUND_DOWN' => floor($totalAmount / $roundingValue) * $roundingValue,
            default => round($totalAmount),
        };

        return [
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * Mengambil metrik transaksi hari ini (omzet finansial & total order).
     * Memindahkan query dari Controller untuk mengembalikan konsistensi arsitektur
     * (Thin Controller) sesuai standar yang sudah diterapkan pada modul lain.
     */
    public function getTodayMetrics(): array
    {
        $today = \Carbon\Carbon::today();
        $metricsQuery = Order::whereDate('order_date', $today)
            ->where('order_status', OrderStatus::Paid->value)
            ->selectRaw('COALESCE(SUM(order_amount), 0) as omzet, COUNT(*) as jumlah')
            ->first();

        return [
            'omzet' => (float) $metricsQuery->omzet,
            'jumlah' => (int) $metricsQuery->jumlah,
        ];
    }
}
