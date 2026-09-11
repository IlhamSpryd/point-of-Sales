<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
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

            // Pajak 10%
            $taxRate = 0.10;
            $taxAmount = (int) round($subtotalAmount * $taxRate);
            $totalAmount = (int) ($subtotalAmount + $taxAmount);

            $paymentMethod = $data['payment_method'] ?? 'cash';
            $cashReceived = $data['cash_received'] ?? null;
            $orderChange = 0;

            if ($paymentMethod === 'cash' && $cashReceived) {
                $orderChange = max(0, (int) $cashReceived - $totalAmount);
            }

            // Status awal: cash = paid, non-cash = pending (menunggu konfirmasi Midtrans)
            $initialStatus = $paymentMethod === 'cash' ? 'paid' : 'pending';

            // Generate order code unik
            do {
                $orderCode = 'POS-' . strtoupper(Str::random(6)) . '-' . rand(100, 999);
            } while (Order::where('order_code', $orderCode)->exists());

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

                // Fix SSL + PHP 8 bug pada Midtrans SDK
                Config::$curlOptions = [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [],
                ];

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
                            'name' => 'Pajak (10%)',
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
                    'bank_transfer' => ['bca_va', 'bni_va', 'bri_va', 'permata_va', 'other_va', 'echannel'],
                    'credit_card' => ['credit_card'],
                    'cstore' => ['indomaret', 'alfamart'],
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
}
