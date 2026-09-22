<?php

// [OMEGA-NODE1] Refactor checkout inti: BOM ingredient deduction (ganti
// pemotongan products.stock untuk produk ber-resep), Split Payments
// (ledger `payments` multi-leg), dan Loyalty Points (ledger append-only
// `loyalty_ledger`) -- seluruhnya dalam SATU DB::transaction() dengan
// disiplin urutan lock: Shift -> Products -> Ingredients ->
// Customer/LoyaltyAccount. | 2026-09-22

declare(strict_types=1);

namespace App\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\LoyaltyLedgerTypeEnum;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethodEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\LoyaltyLedger;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Midtrans\Config;
use Midtrans\Snap;

class TransactionService
{
    /**
     * [OMEGA-NODE1] Kontrak baru $data (SEMUA field baru bersifat OPSIONAL,
     * caller lama tetap jalan tanpa perubahan):
     *
     *   'payments' => [
     *       ['method' => 'cash'|'qris'|'ewallet'|'card', 'amount' => int,
     *        'reference_number' => ?string, 'idempotency_key' => ?string],
     *       ...
     *   ]
     *   KONTRAK: setiap 'amount' adalah NOMINAL BERSIH yang diterapkan ke
     *   tagihan (BUKAN uang tunai mentah yang diterima) -- total SELURUH
     *   leg WAJIB sama persis dengan total tagihan. Kembalian tunai
     *   dihitung & ditampilkan di lapisan UI SEBELUM memanggil Service ini.
     *   Jika 'payments' diisi, order LANGSUNG berstatus Paid (tidak ada
     *   Snap Midtrans) -- dipakai untuk pembayaran yang sudah settled di
     *   tempat (tunai + EDC + QRIS fisik yang dikonfirmasi kasir).
     *
     *   'customer_id' => ?int  -- jika diisi DAN order langsung Paid pada
     *   panggilan ini, poin loyalty otomatis diberikan.
     *
     * Jika 'payments' TIDAK diisi, perilaku 100% identik dengan sebelumnya:
     * 'payment_method' + 'cash_received' tunggal, non-cash => Pending +
     * Snap Token Midtrans.
     *
     * Produk yang memiliki resep BOM (Product::ingredients() tidak kosong)
     * TIDAK LAGI memotong products.stock -- stok bahan baku
     * (ingredients.current_stock) yang dipotong & dikunci sebagai
     * gantinya. Produk TANPA resep tetap memakai model lama
     * (products.stock) untuk kompatibilitas mundur penuh.
     */
    public function createTransaction(array $data, int $userId): Order
    {
        return DB::transaction(function () use ($data, $userId) {
            // [OMEGA-NODE1] Shift-lock guard (UNCHANGED).
            if (! empty($data['shift_id'])) {
                $shift = Shift::lockForUpdate()->find($data['shift_id']);

                if (! $shift || $shift->status !== 'open') {
                    throw ValidationException::withMessages([
                        'shift' => 'Shift Anda baru saja ditutup. Transaksi ini dibatalkan, silakan buka shift baru sebelum melanjutkan.',
                    ]);
                }
            }

            // [OMEGA-NODE7] Validasi STRUKTURAL split payment dilakukan
            // SEDINI MUNGKIN -- sebelum mengunci satu pun baris Product
            // atau Ingredient -- agar payload yang jelas cacat gagal cepat
            // tanpa menahan lock (selaras mandat Zero-Latency Concurrency).
            // Validasi "total leg == total tagihan" TIDAK bisa dilakukan di
            // sini karena totalAmount belum diketahui; itu menyusul setelah
            // calculateOrderTotals().
            $parsedPaymentLegs = null;
            if (! empty($data['payments']) && is_array($data['payments'])) {
                $parsedPaymentLegs = $this->parsePaymentLegs($data['payments']);
            }

            $subtotalAmount = 0;
            $lines = [];
            $bomBreakdownByLineIndex = [];
            // Akumulator kebutuhan bahan baku lintas SELURUH baris order,
            // keyed by ingredient_id => total qty (string bcmath, skala 4).
            $ingredientRequirements = [];
            $items = $data['items'] ?? [];

            // Merge items yang sama agar stok tidak double-decrement (UNCHANGED).
            $mergedItems = collect($items)
                ->groupBy(function ($item) {
                    $optionsSignature = ! empty($item['options']) ? json_encode($item['options']) : '';

                    return $item['product_id'].'|'.$optionsSignature;
                })
                ->map(fn ($group) => [
                    'product_id' => $group->first()['product_id'],
                    'quantity' => $group->sum('quantity'),
                    'extra_price' => $group->first()['extra_price'] ?? 0,
                    'options' => $group->first()['options'] ?? null,
                ])
                ->values()
                ->all();

            $productIds = collect($mergedItems)->pluck('product_id')->toArray();

            // [OMEGA-NODE7] Eager-load resep BOM (ingredients + pivot
            // quantity_required) BERSAMAAN dengan row-lock Product yang
            // sudah ada. Baris resep (`product_ingredients`) adalah master
            // data dan TIDAK dikunci di sini -- hanya
            // `ingredients.current_stock` yang dikunci, terpisah, di
            // bawah, SETELAH lock Product selesai (disiplin urutan lock
            // global: Shift -> Products -> Ingredients -> Customer/Loyalty).
            $products = Product::whereIn('id', $productIds)
                ->with('ingredients')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($mergedItems as $item) {
                $product = $products->find($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Salah satu produk yang dipilih sudah tidak tersedia.',
                    ]);
                }

                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => 'Produk "'.$product->product_name.'" sudah dinonaktifkan dan tidak dapat dijual.',
                    ]);
                }

                $hasBom = $product->ingredients->isNotEmpty();

                // [OMEGA-NODE7] LEGACY STOCK PATH: produk TANPA resep BOM
                // tetap memakai model lama (potong products.stock langsung,
                // divalidasi di sini juga seperti sebelumnya). Produk yang
                // SUDAH punya resep TIDAK PERNAH lagi menyentuh
                // products.stock -- lihat SYNC ALERT staleness di Fase 1.
                if (! $hasBom && $product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => 'Stok produk "'.$product->product_name.'" tidak mencukupi. (Sisa: '.$product->stock.')',
                    ]);
                }

                $unitPrice = $product->product_price + $item['extra_price'];
                $itemSubtotal = $unitPrice * $item['quantity'];
                $subtotalAmount += $itemSubtotal;

                $lineIndex = count($lines);
                $lines[] = [
                    'order_price' => $unitPrice,
                    'qty' => $item['quantity'],
                    'order_subtotal' => $itemSubtotal,
                    'product_id' => $product->id,
                    'options' => $item['options'],
                ];
                $bomBreakdownByLineIndex[$lineIndex] = [];

                if ($hasBom) {
                    foreach ($product->ingredients as $ingredient) {
                        $qtyNeeded = bcmul((string) $ingredient->pivot->quantity_required, (string) $item['quantity'], 4);
                        $ingredientRequirements[$ingredient->id] = bcadd(
                            $ingredientRequirements[$ingredient->id] ?? '0',
                            $qtyNeeded,
                            4
                        );
                        $bomBreakdownByLineIndex[$lineIndex][] = [
                            'ingredient_id' => $ingredient->id,
                            'qty' => $qtyNeeded,
                        ];
                    }
                } else {
                    $product->decrement('stock', $item['quantity']);
                }
            }

            // [OMEGA-NODE7] BOM LOCKING & DEDUCTION.
            // Diurutkan ASCENDING by id, pola deadlock-avoidance yang SAMA
            // dengan lock Product di atas. Kontrak urutan lock global untuk
            // Service ini: Shift -> Products -> Ingredients ->
            // Customer/LoyaltyAccount. Kode lain TIDAK BOLEH mengunci
            // Ingredients sebelum Products, atau jaminan ini runtuh.
            $lockedIngredients = collect();
            if (! empty($ingredientRequirements)) {
                $ingredientIds = array_keys($ingredientRequirements);
                sort($ingredientIds);

                $lockedIngredients = Ingredient::whereIn('id', $ingredientIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($ingredientIds as $ingredientId) {
                    $needed = $ingredientRequirements[$ingredientId];
                    $ingredient = $lockedIngredients->get($ingredientId);

                    if (! $ingredient) {
                        throw ValidationException::withMessages([
                            'items' => 'Bahan baku dengan ID '.$ingredientId.' tidak ditemukan atau sudah diarsipkan.',
                        ]);
                    }

                    if (! $ingredient->is_active) {
                        throw ValidationException::withMessages([
                            'items' => 'Bahan baku "'.$ingredient->name.'" sedang dinonaktifkan dan tidak dapat dipakai.',
                        ]);
                    }

                    if (bccomp((string) $ingredient->current_stock, $needed, 4) < 0) {
                        throw ValidationException::withMessages([
                            'items' => 'Stok bahan baku "'.$ingredient->name.'" tidak mencukupi. '
                                .'(Dibutuhkan: '.$needed.' '.$ingredient->unit.', Tersedia: '.$ingredient->current_stock.' '.$ingredient->unit.')',
                        ]);
                    }
                }

                // [OMEGA-NODE1] PERLU VERIFIKASI DOCS: Model::decrement()
                // menyisipkan $amount sebagai literal SQL mentah ("column -
                // $amount"), bukan parameter ter-bind -- aman di sini karena
                // $needed murni hasil bcmath dari sumber terpercaya (qty
                // integer tervalidasi x quantity_required master data),
                // TIDAK PERNAH berasal dari string input mentah pengguna.
                foreach ($ingredientIds as $ingredientId) {
                    $lockedIngredients->get($ingredientId)->decrement('current_stock', $ingredientRequirements[$ingredientId]);
                }
            }

            ['tax_amount' => $taxAmount, 'total_amount' => $totalAmount] = $this->calculateOrderTotals($subtotalAmount);

            // [OMEGA-NODE7] RESOLUSI METODE PEMBAYARAN.
            $dominantPaymentMethod = 'cash';
            $cashReceivedForReceipt = null;
            $orderChangeForReceipt = 0;
            $finalPaymentLegs = [];

            if ($parsedPaymentLegs !== null) {
                $sumApplied = array_sum(array_column($parsedPaymentLegs, 'amount'));

                if ($sumApplied !== $totalAmount) {
                    throw ValidationException::withMessages([
                        'payments' => 'Total seluruh metode pembayaran (Rp'.number_format($sumApplied, 0, ',', '.')
                            .') tidak sama dengan total tagihan (Rp'.number_format($totalAmount, 0, ',', '.').').',
                    ]);
                }

                $finalPaymentLegs = $parsedPaymentLegs;

                // [OMEGA-NODE1] "Dominant method" untuk kolom ringkas
                // orders.payment_method (legacy, single-value, dipertahankan
                // demi laporan/dashboard/receipt existing yang belum
                // diperbarui). KETERBATASAN DIKETAHUI: App\Enums\PaymentMethod
                // (enum LAMA yang dipakai cast Order::payment_method) TIDAK
                // punya case 'card'. Jika leg terbesar bermetode Card, method
                // dominan di-fallback ke leg non-Card terbesar berikutnya
                // (atau 'qris' bila SELURUH leg Card) agar tidak melempar
                // ValueError setiap kali order ini dibaca ulang. Rincian
                // sesungguhnya (termasuk leg Card) tetap 100% akurat di
                // tabel `payments`. PERLU TINDAK LANJUT: satukan
                // App\Enums\PaymentMethod dengan App\Enums\PaymentMethodEnum.
                $legsByAmountDesc = collect($finalPaymentLegs)->sortByDesc('amount');
                $safeDominant = $legsByAmountDesc->first(fn ($l) => $l['method'] !== PaymentMethodEnum::Card);
                $dominantPaymentMethod = $safeDominant ? $safeDominant['method']->value : 'qris';

                $cashLegTotal = collect($finalPaymentLegs)->where('method', PaymentMethodEnum::Cash)->sum('amount');
                $cashReceivedForReceipt = $cashLegTotal > 0 ? $cashLegTotal : null;
                $orderChangeForReceipt = 0; // kontrak exact-sum -- lihat docblock method ini

                $initialStatus = OrderStatus::Paid;
            } else {
                // LEGACY PATH (UNCHANGED) -- single payment_method, cash =
                // langsung Paid, non-cash = Pending + Midtrans Snap.
                $dominantPaymentMethod = $data['payment_method'] ?? 'cash';
                $cashReceived = $data['cash_received'] ?? null;

                if ($dominantPaymentMethod === 'cash' && (int) $cashReceived < $totalAmount) {
                    throw ValidationException::withMessages([
                        'cash_received' => 'Uang tunai yang diterima tidak mencukupi total tagihan.',
                    ]);
                }

                $cashReceivedForReceipt = $dominantPaymentMethod === 'cash' ? ($cashReceived ?? $totalAmount) : null;
                $orderChangeForReceipt = ($dominantPaymentMethod === 'cash' && $cashReceived)
                    ? max(0, (int) $cashReceived - $totalAmount)
                    : 0;

                $initialStatus = $dominantPaymentMethod === 'cash' ? OrderStatus::Paid : OrderStatus::Pending;
            }

            // Generate order code unik (UNCHANGED).
            do {
                $orderCode = 'POS-'.strtoupper(Str::random(6)).'-'.rand(100, 999);
            } while (Order::where('order_code', $orderCode)->exists());

            $order = Order::forceCreate([
                'user_id' => $userId,
                'shift_id' => $data['shift_id'] ?? null,
                'order_code' => $orderCode,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'order_date' => now()->toDateString(),
                'subtotal_amount' => $subtotalAmount,
                'tax_amount' => $taxAmount,
                'order_amount' => $totalAmount,
                'cash_received' => $cashReceivedForReceipt,
                'order_change' => $orderChangeForReceipt,
                'order_status' => $initialStatus,
                'payment_method' => $dominantPaymentMethod,
                'table_id' => $data['table_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'order_type' => $data['order_type'] ?? OrderType::DineIn->value,
            ]);

            // Buat order items (UNCHANGED bentuknya).
            $createdItems = [];
            foreach ($lines as $line) {
                $createdItems[] = OrderItem::create(array_merge($line, ['order_id' => $order->id]));
            }

            // [OMEGA-NODE7] Ledger bahan baku granular, SETELAH order_item
            // benar-benar punya id (FK order_item_id butuh baris nyata).
            foreach ($lines as $i => $line) {
                $breakdown = $bomBreakdownByLineIndex[$i] ?? [];
                if (empty($breakdown)) {
                    continue;
                }

                $orderItemId = $createdItems[$i]->id;

                foreach ($breakdown as $consumption) {
                    $ingredient = $lockedIngredients->get($consumption['ingredient_id']);
                    $idempotencyKey = 'sale_deduction:'.$order->order_code.':'.$orderItemId.':'.$consumption['ingredient_id'];

                    IngredientStockMovement::create([
                        'ingredient_id' => $consumption['ingredient_id'],
                        'order_id' => $order->id,
                        'order_item_id' => $orderItemId,
                        'type' => IngredientStockMovementTypeEnum::SaleDeduction,
                        'quantity' => bcmul($consumption['qty'], '-1', 4), // signed: keluar = negatif
                        'unit_cost' => $ingredient?->cost_per_unit,
                        'reason' => 'Konsumsi otomatis saat penjualan order '.$order->order_code.'.',
                        'idempotency_key' => $idempotencyKey,
                        'created_by' => $userId,
                    ]);
                }
            }

            // [OMEGA-NODE7] LEDGER PEMBAYARAN.
            if (! empty($finalPaymentLegs)) {
                foreach ($finalPaymentLegs as $i => $leg) {
                    Payment::create([
                        'order_id' => $order->id,
                        'payment_method' => $leg['method'],
                        'amount' => $leg['amount'],
                        'reference_number' => $leg['reference_number'],
                        'status' => PaymentStatusEnum::Captured,
                        'captured_at' => now(),
                        'processed_by' => $userId,
                        'idempotency_key' => $leg['idempotency_key'] ?? ('payment:'.$order->order_code.':'.$i),
                    ]);
                }
            } elseif ($initialStatus === OrderStatus::Paid) {
                // [OMEGA-NODE7] Jalur legacy TUNAI tunggal -- tetap dicatat
                // SATU baris di ledger `payments` agar tabel ini konsisten
                // jadi sumber kebenaran untuk SEMUA order Paid, bukan hanya
                // yang lewat jalur split.
                // PERLU TINDAK LANJUT (di luar cakupan hari ini): jalur
                // non-cash Midtrans (order Pending) BELUM mencatat baris
                // `payments` -- semestinya di-insert oleh
                // updateStatusFromMidtransNotification() saat transisi ke
                // Paid, agar `payments` lengkap untuk SEMUA metode.
                Payment::create([
                    'order_id' => $order->id,
                    'payment_method' => PaymentMethodEnum::from($dominantPaymentMethod),
                    'amount' => $totalAmount,
                    'status' => PaymentStatusEnum::Captured,
                    'captured_at' => now(),
                    'processed_by' => $userId,
                    'idempotency_key' => 'payment:'.$order->order_code.':0',
                ]);
            }

            // [OMEGA-NODE7] LOYALTY POINTS -- hanya untuk order yang
            // LANGSUNG Paid pada panggilan ini (cash / split payment).
            // Order Pending (Midtrans non-cash) SENGAJA tidak diberi poin
            // di sini -- lihat PERLU TINDAK LANJUT di Fase 1.
            if (! empty($data['customer_id']) && $initialStatus === OrderStatus::Paid) {
                $this->grantLoyaltyPoints((int) $data['customer_id'], $order, $subtotalAmount);
            }

            // Snap Token Midtrans HANYA untuk jalur LEGACY non-cash TANPA
            // split payment (UNCHANGED dari revisi sebelumnya).
            if (empty($finalPaymentLegs) && $dominantPaymentMethod !== 'cash') {
                Config::$serverKey = config('services.midtrans.server_key');
                Config::$isProduction = config('services.midtrans.is_production', false);
                Config::$isSanitized = config('services.midtrans.is_sanitized', true);
                Config::$is3ds = config('services.midtrans.is_3ds', true);

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

                $enabledPayments = match ($dominantPaymentMethod) {
                    'qris' => ['other_qris', 'gopay'],
                    'ewallet' => ['gopay', 'shopeepay'],
                    default => [],
                };

                if (! empty($enabledPayments)) {
                    $params['enabled_payments'] = $enabledPayments;
                }

                try {
                    $snapToken = Snap::getSnapToken($params);
                    $order->forceFill(['snap_token' => $snapToken])->save();
                } catch (\Exception $e) {
                    throw new \Exception('Gagal mendapatkan Snap Token Midtrans: '.$e->getMessage());
                }
            }

            return $order;
        }, attempts: 3);
        // [OMEGA-NODE1] attempts: 3 ditambahkan (sebelumnya default 1) --
        // permukaan lock kini lebih besar (Ingredients, Customer). Lihat
        // Fase 1.4 poin 6 untuk trade-off retry vs pemanggilan Snap ganda.
    }

    /**
     * [OMEGA-NODE7] Validasi & normalisasi STRUKTURAL leg pembayaran
     * (bentuk, enum, nominal positif) -- TIDAK memvalidasi jumlah total
     * (butuh totalAmount yang belum diketahui saat method ini dipanggil).
     *
     * @return array<int, array{method: PaymentMethodEnum, amount: int, reference_number: ?string, idempotency_key: ?string}>
     */
    private function parsePaymentLegs(array $rawLegs): array
    {
        $legs = [];

        foreach ($rawLegs as $raw) {
            $rawMethod = $raw['method'] ?? null;

            try {
                $method = $rawMethod instanceof PaymentMethodEnum
                    ? $rawMethod
                    : PaymentMethodEnum::from((string) $rawMethod);
            } catch (\ValueError) {
                throw ValidationException::withMessages([
                    'payments' => 'Metode pembayaran "'.$rawMethod.'" tidak dikenali.',
                ]);
            }

            $amount = (int) ($raw['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'payments' => 'Nominal setiap metode pembayaran harus lebih dari nol.',
                ]);
            }

            $legs[] = [
                'method' => $method,
                'amount' => $amount,
                'reference_number' => $raw['reference_number'] ?? null,
                'idempotency_key' => $raw['idempotency_key'] ?? null,
            ];
        }

        if (empty($legs)) {
            throw ValidationException::withMessages([
                'payments' => 'Minimal satu metode pembayaran wajib diisi.',
            ]);
        }

        return $legs;
    }

    /**
     * [OMEGA-NODE7] Memberikan poin loyalty untuk SATU order yang baru
     * saja Paid. Menulis HANYA ke `loyalty_ledger` -- `customer_loyalty_accounts`
     * DISINKRONKAN OTOMATIS oleh trigger `trg_loyalty_ledger_sync_account`;
     * JANGAN PERNAH menulis manual ke tabel cache itu dari sini.
     *
     * PERLU KONFIRMASI: aturan bisnis perolehan poin belum ditentukan
     * secara resmi. Diimplementasikan SEMENTARA sebagai 1 poin per
     * kelipatan Rp1.000 dari subtotal (SEBELUM pajak), dikalikan
     * points_multiplier tier pelanggan saat ini (default 1.00 bila belum
     * bertier). Mohon konfirmasi rate final sebelum go-live.
     */
    private function grantLoyaltyPoints(int $customerId, Order $order, int $subtotalAmount): void
    {
        // [OMEGA-NODE7] Serialisasi race FIRST-EARN: customer_loyalty_accounts
        // hanya tercipta lewat trigger AFTER INSERT pada loyalty_ledger,
        // sehingga TIDAK ADA baris untuk dikunci pada earn PERTAMA seorang
        // pelanggan -- lockForUpdate() pada baris yang belum ada tidak
        // mengunci apa pun. Kunci baris `customers` induknya LEBIH DULU
        // agar dua transaksi earn PERTAMA untuk pelanggan yang SAMA
        // menunggu secara serial; transaksi kedua akan melihat baris
        // customer_loyalty_accounts yang sudah dibuat transaksi pertama.
        $customer = Customer::where('id', $customerId)->lockForUpdate()->first();

        if (! $customer) {
            Log::warning('[OMEGA-NODE7] Loyalty skip: customer tidak ditemukan.', [
                'customer_id' => $customerId,
                'order_code' => $order->order_code,
            ]);

            return;
        }

        $account = CustomerLoyaltyAccount::lockAndGetAccount($customerId);
        $currentPoints = $account?->current_points ?? 0;
        $multiplier = $account?->currentTier?->points_multiplier ?? 1.00;

        $pointsEarned = (int) floor(($subtotalAmount / 1000) * (float) $multiplier);

        if ($pointsEarned <= 0) {
            return;
        }

        LoyaltyLedger::create([
            'customer_id' => $customerId,
            'order_id' => $order->id,
            'type' => LoyaltyLedgerTypeEnum::Earn,
            'points' => $pointsEarned,
            'balance_after' => $currentPoints + $pointsEarned,
            'reference' => 'Perolehan poin otomatis dari order '.$order->order_code.'.',
            'created_by' => null,
        ]);
    }

    /**
     * Method ini adalah SATU-SATUNYA tempat menghitung total transaksi.
     * (UNCHANGED)
     */
    private function calculateOrderTotals(float $subtotalAmount): array
    {
        $taxRate = config('pos.tax_rate', 0.11);
        $taxAmount = (int) round($subtotalAmount * $taxRate);
        $totalAmount = (int) ($subtotalAmount + $taxAmount);

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
     * Mengambil metrik transaksi hari ini. (UNCHANGED)
     */
    public function getTodayMetrics(): array
    {
        $today = Carbon::today();
        $metricsQuery = Order::whereDate('order_date', $today)
            ->where('order_status', OrderStatus::Paid)
            ->selectRaw('COALESCE(SUM(order_amount), 0) as omzet, COUNT(*) as jumlah')
            ->first();

        return [
            'omzet' => (float) $metricsQuery->omzet,
            'jumlah' => (int) $metricsQuery->jumlah,
        ];
    }

    /**
     * Zero-Trust shift guard. (UNCHANGED)
     *
     * @throws ValidationException jika kasir belum membuka shift.
     */
    public function resolveOpenShiftOrFail(int $userId): int
    {
        $shiftId = Shift::where('user_id', $userId)
            ->where('status', 'open')
            ->value('id');

        if ($shiftId === null) {
            throw ValidationException::withMessages([
                'shift' => 'Anda belum membuka shift kasir. Silakan buka shift terlebih dahulu sebelum memproses transaksi.',
            ]);
        }

        return $shiftId;
    }

    /**
     * Memproses pesanan dari Self-Order QR (Publik). (UNCHANGED -- BELUM
     * BOM/Split-Payment/Loyalty aware, lihat backlog Fase 1.4)
     */
    public function createSelfOrder(Table $table, array $itemsPayload): Order
    {
        return DB::transaction(function () use ($table, $itemsPayload) {
            [$orderItemsData, $subtotalAmount] = $this->buildOrderItemsWithStockLock($itemsPayload);

            ['tax_amount' => $taxAmount, 'total_amount' => $totalAmount] = $this->calculateOrderTotals($subtotalAmount);

            do {
                $orderCode = 'POS-'.strtoupper(Str::random(6)).'-'.rand(100, 999);
            } while (Order::where('order_code', $orderCode)->exists());

            $order = Order::forceCreate([
                'table_id' => $table->id,
                'order_type' => 'dine_in',
                'order_code' => $orderCode,
                'order_date' => now()->toDateString(),
                'subtotal_amount' => $subtotalAmount,
                'tax_amount' => $taxAmount,
                'order_amount' => $totalAmount,
                'order_status' => OrderStatus::Pending,
                'payment_method' => null,
                'user_id' => null,
            ]);

            $order->orderItems()->createMany($orderItemsData);

            return $order;
        });
    }

    // PUBLIC -- dipanggil dari createSelfOrder, createWalkInOrder, DAN dari
    // Livewire computed property untuk preview. (UNCHANGED)
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
            'product_name' => $product->product_name,
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

    /**
     * (UNCHANGED -- BELUM BOM/Split-Payment/Loyalty aware, lihat backlog
     * Fase 1.4: jalur ini masih memotong products.stock langsung untuk
     * SEMUA produk, termasuk yang sudah punya resep BOM.)
     */
    public function createWalkInOrder(
        array $itemsPayload,
        OrderType $orderType,
        ?int $tableId,
        string $paymentMethod,
        int $cashReceived,
        int $userId,
        int $shiftId,
    ): Order {
        return DB::transaction(function () use ($itemsPayload, $orderType, $tableId, $paymentMethod, $cashReceived, $userId, $shiftId) {
            $shift = Shift::lockForUpdate()->find($shiftId);

            if (! $shift || $shift->status !== 'open') {
                throw ValidationException::withMessages([
                    'shift' => 'Shift Anda baru saja ditutup. Transaksi ini dibatalkan, silakan buka shift baru sebelum melanjutkan.',
                ]);
            }

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

            $order = Order::forceCreate([
                'user_id' => $userId,
                'shift_id' => $shiftId,
                'table_id' => $tableId,
                'order_code' => $orderCode,
                'order_date' => now()->toDateString(),
                'order_type' => $orderType,
                'order_status' => OrderStatus::Paid,
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

    // Helper privat dipakai ULANG oleh createSelfOrder & createWalkInOrder. (UNCHANGED)
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

            unset($line['product_name']);

            $subtotal += $line['order_subtotal'];
            $orderItemsData[] = [...$line, 'created_at' => now(), 'updated_at' => now()];
        }

        return [$orderItemsData, $subtotal];
    }

    /**
     * (UNCHANGED -- restoreStockForOrder() masih hanya mengembalikan
     * products.stock, BELUM ingredients.current_stock. Lihat Fase 1.1
     * finding CRITICAL/Backlog.)
     */
    public function payPendingOrder(Order $order, array $rawPaymentLegs, ?int $userId = null, ?int $shiftId = null): Order
    {
        return DB::transaction(function () use ($order, $rawPaymentLegs, $userId, $shiftId) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->order_status->isFinal()) {
                return $order;
            }

            if ($order->order_status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'payments' => 'Pesanan ini tidak dalam status Pending.',
                ]);
            }

            if ($shiftId) {
                $shift = Shift::lockForUpdate()->find($shiftId);
                if (! $shift || $shift->status !== 'open') {
                    throw ValidationException::withMessages([
                        'shift' => 'Shift Anda baru saja ditutup. Transaksi ini dibatalkan.',
                    ]);
                }
            }

            $parsedPaymentLegs = $this->parsePaymentLegs($rawPaymentLegs);
            $sumApplied = array_sum(array_column($parsedPaymentLegs, 'amount'));

            if ($sumApplied !== (int) $order->order_amount) {
                throw ValidationException::withMessages([
                    'payments' => 'Total metode pembayaran (Rp'.number_format($sumApplied, 0, ',', '.').') tidak sama dengan total tagihan (Rp'.number_format((int)$order->order_amount, 0, ',', '.').').',
                ]);
            }

            $legsByAmountDesc = collect($parsedPaymentLegs)->sortByDesc('amount');
            $safeDominant = $legsByAmountDesc->first(fn ($l) => $l['method'] !== PaymentMethodEnum::Card);
            $dominantPaymentMethod = $safeDominant ? $safeDominant['method']->value : 'qris';

            $cashLegTotal = collect($parsedPaymentLegs)->where('method', PaymentMethodEnum::Cash)->sum('amount');
            $cashReceivedForReceipt = $cashLegTotal > 0 ? $cashLegTotal : null;

            foreach ($parsedPaymentLegs as $i => $leg) {
                Payment::create([
                    'order_id' => $order->id,
                    'payment_method' => $leg['method'],
                    'amount' => $leg['amount'],
                    'reference_number' => $leg['reference_number'],
                    'status' => PaymentStatusEnum::Captured,
                    'captured_at' => now(),
                    'processed_by' => $userId,
                    'idempotency_key' => $leg['idempotency_key'] ?? ('payment:'.$order->order_code.':'.$i.':pending'),
                ]);
            }

            $order->forceFill([
                'order_status' => OrderStatus::Paid,
                'payment_method' => $dominantPaymentMethod,
                'cash_received' => $cashReceivedForReceipt,
                'order_change' => 0,
                'shift_id' => $shiftId ?? $order->shift_id,
            ])->save();

            // Ubah status KDS menjadi Pending (masuk dapur)
            $order->orderItems()->update(['preparation_status' => 'pending']);

            if ($order->customer_id) {
                $this->grantLoyaltyPoints((int) $order->customer_id, $order, (int) $order->subtotal_amount);
            }

            return $order;
        }, attempts: 3);
    }

    /**
     * (UNCHANGED -- restoreStockForOrder() masih hanya mengembalikan
     * products.stock, BELUM ingredients.current_stock. Lihat Fase 1.1
     * finding CRITICAL/Backlog.)
     */
    public function updateStatusFromMidtransNotification(
        string $orderCode,
        string $transactionStatus,
        ?string $fraudStatus,
        ?int $grossAmount = null,
    ): Order {
        return DB::transaction(function () use ($orderCode, $transactionStatus, $fraudStatus, $grossAmount) {
            $order = Order::where('order_code', $orderCode)->lockForUpdate()->firstOrFail();

            if ($order->order_status->isFinal()) {
                return $order;
            }

            if ($grossAmount !== null && $grossAmount !== (int) $order->order_amount) {
                Log::critical('Midtrans notification GROSS AMOUNT MISMATCH -- status TIDAK diubah demi keamanan.', [
                    'order_code' => $orderCode,
                    'order_amount_di_database' => (int) $order->order_amount,
                    'gross_amount_dari_midtrans' => $grossAmount,
                    'transaction_status' => $transactionStatus,
                ]);

                return $order;
            }

            $newStatus = match (true) {
                in_array($transactionStatus, ['capture', 'settlement'], true) && $fraudStatus !== 'challenge' => OrderStatus::Paid,
                $transactionStatus === 'deny' => OrderStatus::Failed,
                $transactionStatus === 'cancel' => OrderStatus::Cancelled,
                $transactionStatus === 'expire' => OrderStatus::Expired,
                default => null,
            };

            if ($newStatus !== null) {
                if ($newStatus === OrderStatus::Paid && $order->order_status !== OrderStatus::Paid) {
                    $legMethod = $order->payment_method ? (is_string($order->payment_method) ? $order->payment_method : $order->payment_method->value) : 'qris';
                    
                    $this->payPendingOrder(
                        $order,
                        [['method' => $legMethod, 'amount' => (int) $order->order_amount, 'reference_number' => 'MIDTRANS-'.$transactionStatus]],
                        null,
                        null
                    );
                } else {
                    $order->forceFill(['order_status' => $newStatus])->save();

                    if (in_array($newStatus, [OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Expired], true)) {
                        $this->restoreStockForOrder($order);
                    }
                }
            }

            return $order;
        }, attempts: 3);
    }

    /**
     * (UNCHANGED)
     */
    private function restoreStockForOrder(Order $order): void
    {
        $items = $order->orderItems()->get();

        if ($items->isEmpty()) {
            return;
        }

        $productIds = $items->pluck('product_id')->unique()->sort()->values();

        $products = Product::withTrashed()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            if (! $product) {
                Log::critical('[OMEGA-NODE1] Stock restore GAGAL: produk tidak ditemukan.', [
                    'order_code' => $order->order_code,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                ]);

                continue;
            }

            $idempotencyKey = 'stock_restore:'.$order->order_code.':'.$item->id;

            $movement = StockMovement::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'product_id' => $product->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'type' => StockMovementType::RestoreCompensation,
                    'quantity' => $item->qty,
                    'reason' => "Kompensasi stok otomatis: order {$order->order_code} berubah ke status {$order->order_status->value}.",
                ]
            );

            if ($movement->wasRecentlyCreated) {
                $product->increment('stock', $item->qty);
            }
        }
    }
}
