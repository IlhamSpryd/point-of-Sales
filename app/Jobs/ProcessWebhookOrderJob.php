<?php

declare(strict_types=1);

// Implementasi nyata: sebelumnya method ini hanya berisi
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

use App\Core\Context\TenantContext;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Services\SystemUserResolver;
use App\Modules\Sales\Models\ChannelOrderLog;
use App\Modules\Sales\Models\ChannelProductMapping;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\Omnichannel\ChannelOrderNormalizerFactory;
use App\Modules\Sales\Services\TransactionService;
use App\Platform\Database\DatabaseErrorClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ProcessWebhookOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Satu percobaan -- kegagalan dicatat 'failed', BUKAN auto-retry (idempotency ditegakkan manual di bawah + unique constraint DB). */
    public int $tries = 1;

    public function __construct(
        public ChannelOrderLog $log,
        public ?int $tenantId = null,
        public ?int $storeId = null
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(TransactionService $transactionService): void
    {
        $affected = ChannelOrderLog::withoutGlobalScopes()
            ->where('id', $this->log->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($affected === 0) {
            return;
        }

        $this->log->status = 'processing';

        $tenantId = $this->tenantId ?? $this->log->tenant_id;
        $storeId = $this->storeId ?? $this->log->store_id;

        // D1-DB-006: job async TIDAK BOLEH berjalan tanpa konteks tenant
        // (fail-open pada queue worker berumur panjang membocorkan data).
        if (! $tenantId) {
            $this->fail('Channel order log tidak memiliki tenant_id; job ditolak (fail-closed).', warning: true);

            return;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant || $tenant->trashed() || $tenant->isSuspended()) {
            $this->fail('Tenant tidak ditemukan atau sedang disuspensi; job ditolak.', warning: true);

            return;
        }

        app(TenantContext::class)->runAs((int) $tenantId, $storeId !== null ? (int) $storeId : null, function () use ($transactionService) {
            $this->processInsideContext($transactionService);
        });
    }

    private function processInsideContext(TransactionService $transactionService): void
    {
        try {
            $order = $this->processOrder($transactionService);
            $this->log->update([
                'status' => 'completed',
                'order_id' => $order->id,
                'error_message' => null,
            ]);
        } catch (ValidationException $e) {
            $this->fail(collect($e->errors())->flatten()->first() ?? 'Validasi checkout gagal.', warning: true);
        } catch (QueryException $e) {
            $idempotencyKey = $this->deterministicIdempotencyKey();
            if (DatabaseErrorClassifier::isDuplicateEntry($e) && ($existing = Order::where('idempotency_key', $idempotencyKey)->first())) {
                $this->log->update([
                    'status' => 'completed',
                    'order_id' => $existing->id,
                    'error_message' => null,
                ]);

                return;
            }

            $this->fail($e->getMessage(), warning: false);
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

        $config = \Illuminate\Support\Facades\DB::table('ofd_channel_configs')
            ->where('tenant_id', $this->log->tenant_id)
            ->where('provider', $this->log->provider)
            ->when($this->log->store_id, fn ($q) => $q->where('store_id', $this->log->store_id))
            ->where('is_active', true)
            ->first();

        $commissionRate = $config ? (float) $config->commission_rate_pct : 20.00;
        $priceMarkupPct = $config ? (float) $config->price_markup_pct : 0.00;

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

            $unitPrice = (int) $product->product_price;
            if ($priceMarkupPct > 0) {
                $unitPrice = (int) round($unitPrice * (1 + ($priceMarkupPct / 100)));
            }

            $qty = max(1, $normalizedItem->quantity);
            $subtotal += $unitPrice * $qty;

            $items[] = ['product_id' => $productId, 'quantity' => $qty, 'extra_price' => 0, 'options' => null];
        }

        $systemUserId = SystemUserResolver::channel($this->log->tenant_id)->id;
        if (! $systemUserId) {
            throw new InvalidArgumentException(
                'Akun sistem channel order ("'.config('pos.channel_order_system_email').'") belum terdaftar. '.
                'Lihat SYNC ALERT NODE 1.'
            );
        }

        $totals = $transactionService->calculateOrderTotals($subtotal);
        $totalAmount = $totals['total_amount'];
        $taxAmount = $totals['tax_amount'];

        $merchantPromoSubsidy = (float) ($this->log->payload['merchant_promo_subsidy'] ?? $this->log->payload['promo_subsidy'] ?? 0);
        $commissionAmount = round($subtotal * ($commissionRate / 100), 2);
        $netPayout = round($subtotal + $taxAmount - $merchantPromoSubsidy - $commissionAmount, 2);

        // Auto customer matching for omnichannel order (Modul 6)
        $customerId = null;
        if (! empty($normalized->customerPhone) || ! empty($normalized->customerEmail) || ! empty($normalized->customerName)) {
            $customerQuery = Customer::withoutGlobalScopes()->where('tenant_id', $this->log->tenant_id);
            if (! empty($normalized->customerPhone)) {
                $matchedCustomer = (clone $customerQuery)->where('phone', $normalized->customerPhone)->first();
            } elseif (! empty($normalized->customerEmail)) {
                $matchedCustomer = (clone $customerQuery)->where('email', $normalized->customerEmail)->first();
            } else {
                $matchedCustomer = null;
            }

            if (! $matchedCustomer && (! empty($normalized->customerPhone) || ! empty($normalized->customerName))) {
                $custCode = 'CUST-OMNI-'.substr(md5(($normalized->customerPhone ?? '').($normalized->customerName ?? '').Str::random(4)), 0, 8);
                $matchedCustomer = Customer::create([
                    'tenant_id' => $this->log->tenant_id,
                    'customer_code' => strtoupper($custCode),
                    'name' => $normalized->customerName ?: ('Pelanggan '.ucfirst($this->log->provider)),
                    'phone' => $normalized->customerPhone,
                    'email' => $normalized->customerEmail,
                    'customer_type' => 'INDIVIDUAL',
                    'is_active' => true,
                ]);
            }

            $customerId = $matchedCustomer?->id;
        }

        $order = $transactionService->createTransaction([
            'items' => $items,
            'payments' => [[
                'method' => PaymentMethod::Ewallet->value,
                'amount' => $totalAmount,
                'reference_number' => strtoupper($this->log->provider).'-'.$normalized->externalOrderId,
            ]],
            'customer_id' => $customerId,
            'table_id' => null,
            'order_type' => OrderType::Takeaway->value,
            'order_channel' => $this->log->provider,
            'origin_channel' => in_array($this->log->provider, ['gofood', 'grabfood'], true) ? $this->log->provider : 'other_external',
            'external_order_id' => $normalized->externalOrderId,
            'external_short_code' => $this->log->payload['short_code'] ?? strtoupper(substr($this->log->provider, 0, 2)).'-'.substr($normalized->externalOrderId, -4),
            'driver_name' => $this->log->payload['driver_name'] ?? $this->log->payload['driver']['name'] ?? null,
            'driver_phone' => $this->log->payload['driver_phone'] ?? $this->log->payload['driver']['phone'] ?? null,
            'platform_commission_rate' => $commissionRate,
            'platform_commission_amount' => $commissionAmount,
            'merchant_promo_subsidy' => $merchantPromoSubsidy,
            'net_payout_amount' => $netPayout,
            'idempotency_key' => $idempotencyKey,
        ], (int) $systemUserId);

        // Dispatch balanced PSAK GL outbox event
        app(\App\Modules\Finance\Services\FinancialOutboxDispatcherService::class)->dispatchOfdOrderCompleted(
            $order,
            $commissionAmount,
            $merchantPromoSubsidy
        );

        return $order;
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

        $warning ? Log::warning('Webhook order gagal (validasi).', $context)
                 : Log::error('Webhook order gagal (sistem).', $context);
    }
}
