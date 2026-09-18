<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            // PERUBAHAN: Grouping SEBELUMNYA hanya berdasarkan product_id, sehingga
            // "Kopi Susu - Ice" dan "Kopi Susu - Hot" akan salah digabung jadi satu baris
            // dan kehilangan harga tambahan dari modifier (extra_price). Sekarang kita
            // gabungkan HANYA jika product_id DAN kombinasi varian (options) sama persis.
            // Item dari Kasir (yang tidak pernah mengirim 'options') tetap berperilaku
            // SAMA seperti sebelumnya — 100% backward compatible, tidak ada regresi.
            $mergedItems = collect($items)
                ->groupBy(function ($item) {
                    $optionsSignature = ! empty($item['options']) ? json_encode($item['options']) : '';

                    return $item['product_id'].'|'.$optionsSignature;
                })
                ->map(fn ($group) => [
                    'product_id' => $group->first()['product_id'],
                    'quantity' => $group->sum('quantity'),
                    // extra_price = total tambahan harga dari modifier (misal Oat Milk +5000).
                    // Selalu 0 untuk item dari Kasir yang tidak mengenal konsep modifier.
                    'extra_price' => $group->first()['extra_price'] ?? 0,
                    // options = data mentah varian untuk "dibekukan" ke order_details nanti.
                    'options' => $group->first()['options'] ?? null,
                ])
                ->values()
                ->all();

            $productIds = collect($mergedItems)->pluck('product_id')->toArray();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($mergedItems as $item) {
                $product = $products->find($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Salah satu produk yang dipilih sudah tidak tersedia.',
                    ]);
                }

                // GUARD TERAKHIR: Baris produk ini sudah di-lock (lockForUpdate) di atas,
                // jadi ini titik paling aman untuk mengecek status aktif. Pengecekan ini
                // menutup celah race condition — kasus di mana Admin menonaktifkan produk
                // PERSIS saat kasir sedang menekan tombol bayar (setelah lolos validasi
                // Form Request, tapi sebelum stok benar-benar dipotong).
                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => 'Produk "'.$product->product_name.'" sudah dinonaktifkan dan tidak dapat dijual.',
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => 'Stok produk "'.$product->product_name.'" tidak mencukupi. (Sisa: '.$product->stock.')',
                    ]);
                }

                // PERUBAHAN: harga satuan sekarang memperhitungkan extra_price dari modifier.
                // Untuk item Kasir, extra_price selalu 0 sehingga hasilnya identik dengan
                // perhitungan lama (product_price saja) — tidak ada perubahan perilaku.
                $unitPrice = $product->product_price + $item['extra_price'];
                $itemSubtotal = $unitPrice * $item['quantity'];
                $subtotalAmount += $itemSubtotal;

                $lines[] = [
                    'order_price' => $unitPrice,
                    'qty' => $item['quantity'],
                    'order_subtotal' => $itemSubtotal,
                    'product_id' => $product->id,
                    'options' => $item['options'], // dibekukan apa adanya ke order_details
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
                $orderCode = 'POS-'.strtoupper(Str::random(6)).'-'.rand(100, 999);
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
                'table_id' => $data['table_id'] ?? null,
            ]);

            // Buat order details
            foreach ($lines as $line) {
                OrderItem::create(array_merge($line, ['order_id' => $order->id]));
            }

            // Jika pembayaran non-tunai, generate Snap Token Midtrans
            if ($paymentMethod !== 'cash') {
                Config::$serverKey = config('services.midtrans.server_key');
                Config::$isProduction = config('services.midtrans.is_production', false);
                Config::$isSanitized = config('services.midtrans.is_sanitized', true);
                Config::$is3ds = config('services.midtrans.is_3ds', true);

                // Fix SSL + PHP 8 bug pada Midtrans SDK (hanya untuk sandbox)
                if (! config('services.midtrans.is_production', false)) {
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
                            'id' => 'ORDER-'.$order->id,
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

                if (! empty($enabledPayments)) {
                    $params['enabled_payments'] = $enabledPayments;
                }

                try {
                    $snapToken = Snap::getSnapToken($params);
                    $order->update(['snap_token' => $snapToken]);
                } catch (\Exception $e) {
                    throw new \Exception('Gagal mendapatkan Snap Token Midtrans: '.$e->getMessage());
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
        $today = Carbon::today();
        $metricsQuery = Order::whereDate('order_date', $today)
            ->where('order_status', OrderStatus::Paid->value)
            ->selectRaw('COALESCE(SUM(order_amount), 0) as omzet, COUNT(*) as jumlah')
            ->first();

        return [
            'omzet' => (float) $metricsQuery->omzet,
            'jumlah' => (int) $metricsQuery->jumlah,
        ];
    }

    /**
     * Memproses pesanan dari Self-Order QR (Publik).
     * Fokus pada validasi server-side modifier.
     */
    public function createSelfOrder(Table $table, array $itemsPayload): Order
    {
        return DB::transaction(function () use ($table, $itemsPayload) {
            [$orderItemsData, $subtotalAmount] = $this->buildOrderItemsWithStockLock($itemsPayload);

            ['tax_amount' => $taxAmount, 'total_amount' => $totalAmount] = $this->calculateOrderTotals($subtotalAmount);

            do {
                $orderCode = 'POS-'.strtoupper(Str::random(6)).'-'.rand(100, 999);
            } while (Order::where('order_code', $orderCode)->exists());

            $order = Order::create([
                'table_id' => $table->id,
                'order_type' => 'dine_in',
                'order_code' => $orderCode,
                'order_date' => now()->toDateString(),
                'subtotal_amount' => $subtotalAmount,
                'tax_amount' => $taxAmount,
                'order_amount' => $totalAmount,
                'order_status' => OrderStatus::Pending->value,
                'payment_method' => null,
                'user_id' => null,
            ]);

            $order->orderItems()->createMany($orderItemsData);

            return $order;
        });
    }

    // PUBLIC -- dipanggil dari createSelfOrder, createWalkInOrder, DAN dari
    // Livewire computed property untuk preview (read-only, aman dipanggil
    // berkali-kali per render, tidak menyentuh stok).
    public function resolveOrderItemLine(Product $product, array $modifierIds, int $qty, ?string $notes): array
    {
        $requiredGroupIds = $product->modifierGroups()
            ->where('is_required', true)->pluck('modifier_groups.id');

        $selectedModifiers = Modifier::whereIn('id', $modifierIds)->with('modifierGroup')->get();
        $selectedGroupIds = $selectedModifiers->pluck('modifierGroup.id')->unique();

        foreach ($requiredGroupIds as $groupId) {
            if (! $selectedGroupIds->contains($groupId)) {
                throw ValidationException::withMessages([
                    'items' => "Pilihan wajib untuk {$product->product_name} belum lengkap.",
                ]);
            }
        }

        // --- VALIDASI SELECTION TYPE SINGLE ---
        // Karena form array bisa saja meloloskan 2 modifier pada grup single
        $productGroups = $product->modifierGroups;
        foreach ($productGroups as $group) {
            $selectedForGroup = $selectedModifiers->where('modifier_group_id', $group->id)->count();
            if ($group->selection_type === 'single' && $selectedForGroup > 1) {
                throw ValidationException::withMessages(['items' => "Varian {$group->name} hanya boleh dipilih satu untuk {$product->product_name}."]);
            }
        }

        $extraPrice = $selectedModifiers->sum('extra_price');
        $unitPrice = $product->product_price + $extraPrice;
        $lineSubtotal = $unitPrice * $qty;

        return [
            'product_id' => $product->id,
            'product_name' => $product->product_name, // untuk tampilan cart saja
            'qty' => $qty,
            'order_price' => $unitPrice,
            'order_subtotal' => $lineSubtotal,
            'notes' => $notes,
            'preparation_status' => 'pending',
            'options' => $selectedModifiers->map(fn ($m) => [
                'modifier_id' => $m->id, 'name' => $m->name, 'extra_price' => $m->extra_price,
            ])->values()->toArray(),
        ];
    }

    public function createWalkInOrder(
        array $itemsPayload,
        OrderType $orderType,
        ?int $tableId,
        string $paymentMethod,
        int $cashReceived,
    ): Order {
        return DB::transaction(function () use ($itemsPayload, $orderType, $tableId, $paymentMethod, $cashReceived) {
            [$orderItemsData, $subtotal] = $this->buildOrderItemsWithStockLock($itemsPayload);

            ['tax_amount' => $taxAmount, 'total_amount' => $totalAmount] = $this->calculateOrderTotals($subtotal);

            if ($paymentMethod === 'cash' && $cashReceived < $totalAmount) {
                throw ValidationException::withMessages([
                    'cashReceived' => 'Uang tunai yang diterima kurang dari total belanja.',
                ]);
            }

            do {
                $orderCode = 'POS-'.strtoupper(Str::random(6)).'-'.rand(100, 999);
            } while (Order::where('order_code', $orderCode)->exists());

            $order = Order::create([
                'table_id' => $tableId, // null aman untuk takeaway (kolom sudah nullable)
                'order_code' => $orderCode,
                'order_date' => now()->toDateString(),
                'order_type' => $orderType,
                'order_status' => OrderStatus::Paid->value, // kasir = bayar di tempat, langsung Paid
                'subtotal_amount' => $subtotal,
                'tax_amount' => $taxAmount,
                'order_amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'cash_received' => $cashReceived,
                'order_change' => max(0, $cashReceived - $totalAmount),
            ]);

            $order->orderItems()->createMany($orderItemsData);

            return $order->load('orderItems.product');
        });
    }

    // Helper privat yang dipakai ULANG oleh createSelfOrder & createWalkInOrder
    private function buildOrderItemsWithStockLock(array $itemsPayload): array
    {
        $orderItemsData = [];
        $subtotal = 0;

        foreach ($itemsPayload as $itemInput) {
            $product = Product::availableForOrder()->lockForUpdate()->findOrFail($itemInput['product_id']);
            if ($product->stock < $itemInput['qty']) {
                throw ValidationException::withMessages([
                    'items' => "Stok {$product->product_name} tidak cukup.",
                ]);
            }
            $product->decrement('stock', $itemInput['qty']);

            $line = $this->resolveOrderItemLine($product, $itemInput['modifier_ids'] ?? [], $itemInput['qty'], $itemInput['notes'] ?? null);

            // Hapus atribut view-only sebelum simpan DB
            unset($line['product_name']);

            $subtotal += $line['order_subtotal'];
            $orderItemsData[] = [...$line, 'created_at' => now(), 'updated_at' => now()];
        }

        return [$orderItemsData, $subtotal];
    }

    /**
     * Memperbarui status Order berdasarkan notifikasi webhook Midtrans
     * (dipanggil oleh MidtransNotificationController setelah signature
     * terverifikasi). Dipakai untuk SEMUA pembayaran non-tunai, baik dari
     * Self-Order pelanggan maupun Kasir POS.
     */
    public function updateStatusFromMidtransNotification(string $orderCode, string $transactionStatus, ?string $fraudStatus): Order
    {
        return DB::transaction(function () use ($orderCode, $transactionStatus, $fraudStatus) {
            $order = Order::where('order_code', $orderCode)->lockForUpdate()->firstOrFail();

            // Idempotency guard: status final TIDAK PERNAH ditimpa ulang.
            if (OrderStatus::from($order->order_status)->isFinal()) {
                return $order;
            }

            $newStatus = match (true) {
                in_array($transactionStatus, ['capture', 'settlement'], true) && $fraudStatus !== 'challenge' => OrderStatus::Paid,
                $transactionStatus === 'deny' => OrderStatus::Failed,
                $transactionStatus === 'cancel' => OrderStatus::Cancelled,
                $transactionStatus === 'expire' => OrderStatus::Expired,
                default => null, // 'pending', 'authorize', dll -- notifikasi Midtrans yang BELUM final.
                                  // TANPA arm ini, match() melempar UnhandledMatchError untuk setiap
                                  // notifikasi 'pending' -- padahal itu JUSTRU notifikasi PALING SERING
                                  // dikirim Midtrans (dikirim pertama kali begitu customer membuka
                                  // popup Snap, sebelum pembayaran selesai).
            };

            if ($newStatus !== null) {
                $order->update(['order_status' => $newStatus->value]);
            }

            return $order;
        });
    }
}
