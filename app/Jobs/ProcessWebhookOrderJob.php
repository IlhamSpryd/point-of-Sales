<?php

declare(strict_types=1);

// [OMEGA-NODE5] Implementasi nyata: sebelumnya method ini hanya berisi
// komentar TODO, tidak pernah membuat Order. Sekarang menjembatani payload
// GrabFood/GoFood ke TransactionService::createTransaction() -- SATU
// titik masuk checkout yang sama dipakai Kasir & Self-Order.
//
// ZERO-TRUST: total tagihan provider TIDAK PERNAH dipakai sebagai nominal
// bayar. Subtotal dihitung ulang dari Product::product_price internal,
// lalu rumus pajak/pembulatan Node 1 direplikasi (lihat SYNC ALERT NODE 1
// untuk permintaan agar formula ini di-expose resmi, bukan diduplikasi). |
// 2026-09-23

namespace App\Jobs;

use App\Enums\OrderType;
use App\Enums\PaymentMethodEnum;
use App\Models\ChannelOrderLog;
use App\Models\ChannelProductMapping;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Omnichannel\ChannelOrderNormalizerFactory;
use App\Services\TransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ProcessWebhookOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Satu percobaan -- kegagalan dicatat 'failed', BUKAN auto-retry (idempotency ditegakkan manual di bawah + unique constraint DB). */
    public int $tries = 1;

    public function __construct(public ChannelOrderLog $log) {}

    public function handle(TransactionService $transactionService): void
    {
        if ($this->log->status !== 'pending') {
            return;
        }

        $this->log->update(['status' => 'processing']);

        try {
            $order = $this->processOrder($transactionService);

            $this->log->update([
                'status' => 'completed',
                'order_id' => $order->id,
                'error_message' => null,
            ]);
        } catch (ValidationException $e) {
            $this->fail(collect($e->errors())->flatten()->first() ?? 'Validasi checkout gagal.', warning: true);
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), warning: false);
        }
    }

    private function processOrder(TransactionService $transactionService): Order
    {
        $idempotencyKey = $this->deterministicIdempotencyKey();

        // Lapis idempotency PERTAMA: jika order untuk log ini sudah pernah
        // sukses dibuat (mis. redispatch manual setelah status direset),
        // jangan buat order kedua. unique constraint orders.idempotency_key
        // adalah jaring pengaman KEDUA jika pengecekan ini kalah race.
        if ($existing = Order::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $normalizer = ChannelOrderNormalizerFactory::make($this->log->provider);
        $normalized = $normalizer->normalize($this->log->payload);

        if (empty($normalized->items)) {
            throw new InvalidArgumentException('Payload tidak berisi item pesanan yang valid.');
        }

        $externalIds = collect($normalized->items)->pluck('externalProductId')->unique()->values();

        $mappings = ChannelProductMapping::query()
            ->where('provider', $this->log->provider)
            ->whereIn('external_product_id', $externalIds)
            ->pluck('product_id', 'external_product_id');

        $unmapped = $externalIds->diff($mappings->keys());
        if ($unmapped->isNotEmpty()) {
            throw new InvalidArgumentException(
                'SKU belum dipetakan ke produk internal: '.$unmapped->implode(', ').
                '. Tambahkan pemetaan di menu Integrasi Channel, lalu proses ulang order ini.'
            );
        }

        $products = Product::whereIn('id', $mappings->values())->where('is_active', true)->get()->keyBy('id');

        $items = [];
        $subtotal = 0;
        foreach ($normalized->items as $normalizedItem) {
            $productId = (int) $mappings[$normalizedItem->externalProductId];
            $product = $products->get($productId);

            if (! $product) {
                throw new InvalidArgumentException(
                    "Produk internal #{$productId} (SKU {$normalizedItem->externalProductId}) sudah tidak aktif/ditemukan."
                );
            }

            $qty = max(1, $normalizedItem->quantity);
            $subtotal += $product->product_price * $qty;

            $items[] = ['product_id' => $productId, 'quantity' => $qty, 'extra_price' => 0, 'options' => null];
        }

        $systemUserId = User::where('email', config('pos.channel_order_system_email'))->value('id');
        if (! $systemUserId) {
            throw new InvalidArgumentException(
                'Akun sistem channel order ("'.config('pos.channel_order_system_email').'") belum terdaftar. '.
                'Lihat SYNC ALERT NODE 1.'
            );
        }

        return $transactionService->createTransaction([
            'items' => $items,
            'payments' => [[
                'method' => PaymentMethodEnum::Ewallet->value,
                'amount' => $this->replicateTotalAmount($subtotal),
                'reference_number' => strtoupper($this->log->provider).'-'.$normalized->externalOrderId,
            ]],
            'table_id' => null,
            'order_type' => OrderType::Takeaway->value,
            'idempotency_key' => $idempotencyKey,
        ], (int) $systemUserId);
    }

    /**
     * REPLIKASI SENGAJA dari TransactionService::calculateOrderTotals()
     * (private, tak bisa dipanggil dari luar). Jika Node 1 mengubah rumus
     * tanpa mengabarkan Node 5, dua formula ini drift dan SETIAP order
     * channel gagal dengan pesan "Total pembayaran tidak sama dengan
     * tagihan" -- risiko ini didokumentasikan di SYNC ALERT NODE 1.
     */
    private function replicateTotalAmount(int $subtotalAmount): int
    {
        $taxRate = config('pos.tax_rate', 0.11);
        $taxAmount = (int) round($subtotalAmount * $taxRate);
        $totalAmount = (int) ($subtotalAmount + $taxAmount);

        $roundingValue = config('pos.rounding_value', 100);
        $roundingBehavior = config('pos.rounding_behavior', 'ROUND_NEAREST');

        return (int) match ($roundingBehavior) {
            'ROUND_NEAREST' => round($totalAmount / $roundingValue) * $roundingValue,
            'ROUND_UP' => ceil($totalAmount / $roundingValue) * $roundingValue,
            'ROUND_DOWN' => floor($totalAmount / $roundingValue) * $roundingValue,
            default => round($totalAmount),
        };
    }

    private function deterministicIdempotencyKey(): string
    {
        return substr(hash('sha256', "channel:{$this->log->provider}:{$this->log->external_order_id}"), 0, 36);
    }

    private function fail(string $message, bool $warning): void
    {
        $this->log->update(['status' => 'failed', 'error_message' => $message]);

        $context = [
            'provider' => $this->log->provider,
            'external_order_id' => $this->log->external_order_id,
            'message' => $message,
        ];

        $warning ? Log::warning('[OMEGA-NODE5] Webhook order gagal (validasi).', $context)
                 : Log::error('[OMEGA-NODE5] Webhook order gagal (sistem).', $context);
    }
}
