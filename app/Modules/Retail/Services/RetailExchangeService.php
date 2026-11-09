<?php

declare(strict_types=1);

namespace App\Modules\Retail\Services;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RetailExchangeService
{
    public function __construct(
        protected InventoryLedgerService $inventoryLedgerService,
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext,
        protected FinancialOutboxDispatcherService $financialOutboxDispatcher
    ) {}

    /**
     * Alur tukar guling ukuran/varian ritel (Variant Exchange).
     *
     * Invariant:
     * 1. Retur varian lama ke stok karantina (inbound movement).
     * 2. Potong varian baru dari stok cabang (outbound movement).
     * 3. Jika ada selisih harga > 0: tagih kasir via payments & catat cash drawer + FinancialEventOutbox.
     * 4. Jika selisih harga < 0: kembalikan uang via payments & catat cash drawer out + FinancialEventOutbox.
     *
     * @return array{order_id: int, old_variant_id: int, new_variant_id: int, quantity: int, price_difference: float, status: string, payment_id: int|null}
     *
     * @throws DomainException|InvalidArgumentException
     */
    public function exchangeVariant(
        int $tenantId,
        int $storeId,
        int $orderId,
        int $oldVariantId,
        int $newVariantId,
        int $quantity,
        int $userId,
        ?string $reason = null,
        string $paymentMethod = 'cash',
        float|int|null $amountTendered = null,
        ?int $shiftId = null
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Kuantitas tukar guling harus lebih dari 0.');
        }

        if ($oldVariantId === $newVariantId) {
            throw new InvalidArgumentException('Varian pengganti tidak boleh sama dengan varian yang ditukar.');
        }

        return DB::transaction(function () use (
            $tenantId, $storeId, $orderId, $oldVariantId, $newVariantId, $quantity, $userId, $reason, $paymentMethod, $amountTendered, $shiftId
        ) {
            $order = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new DomainException('Pesanan asal tidak ditemukan.');
            }

            if (! in_array($order->order_status, [OrderStatus::Paid, OrderStatus::Completed], true)) {
                $statusVal = $order->order_status->value;
                throw new DomainException("Hanya pesanan berstatus lunas yang dapat diproses tukar guling. Status saat ini: {$statusVal}.");
            }

            // 1. Verifikasi item pesanan asal memuat varian lama
            $orderItem = OrderItem::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('product_variant_id', $oldVariantId)
                ->first();

            if (! $orderItem) {
                throw new DomainException("Varian lama #{$oldVariantId} tidak ditemukan dalam daftar pembelian pesanan ini.");
            }

            if ($quantity > (int) $orderItem->qty) {
                throw new DomainException("Jumlah tukar ({$quantity}) melebihi kuantitas pembelian ({$orderItem->qty}).");
            }

            $oldVariant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $oldVariantId)
                ->with('product')
                ->first();

            $newVariant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $newVariantId)
                ->with('product')
                ->first();

            if (! $oldVariant || ! $newVariant) {
                throw new DomainException('Data varian lama atau varian baru tidak ditemukan.');
            }

            $oldProduct = $oldVariant->product;
            $newProduct = $newVariant->product;
            $oldProductPrice = $oldProduct instanceof Product ? (float) $oldProduct->product_price : 0.0;
            $newProductPrice = $newProduct instanceof Product ? (float) $newProduct->product_price : 0.0;

            // 2. Pemulihan Stok: Masukkan varian lama ke stok retur / karantina
            $this->inventoryLedgerService->recordMovement(
                tenantId: $tenantId,
                storeId: $storeId,
                itemType: 'product',
                itemId: (int) $oldVariant->product_id,
                quantityDelta: (float) $quantity,
                type: StockMovementType::RestoreCompensation,
                unitCost: $oldProductPrice,
                reason: "Tukar guling varian lama (#{$oldVariant->id}) ke karantina".($reason ? ": {$reason}" : ''),
                orderId: $order->id,
                userId: $userId,
                variantId: (int) $oldVariant->id
            );

            // 3. Pemotongan Stok: Kurangi varian baru dari stok cabang
            $this->inventoryLedgerService->recordMovement(
                tenantId: $tenantId,
                storeId: $storeId,
                itemType: 'product',
                itemId: (int) $newVariant->product_id,
                quantityDelta: -((float) $quantity),
                type: StockMovementType::SaleDeduction,
                unitCost: $newProductPrice,
                reason: "Tukar guling varian baru (#{$newVariant->id}) ke pelanggan".($reason ? ": {$reason}" : ''),
                orderId: $order->id,
                userId: $userId,
                variantId: (int) $newVariant->id
            );

            // 4. Hitung Selisih Finansial
            $oldUnitPrice = (float) ($oldVariant->price_override ?? $oldProductPrice);
            $newUnitPrice = (float) ($newVariant->price_override ?? $newProductPrice);
            $priceDiff = ($newUnitPrice - $oldUnitPrice) * $quantity;
            $absDiff = round(abs($priceDiff), 2);
            $diffInt = (int) round($absDiff);
            $paymentId = null;

            // Cari shift aktif kasir jika belum diberikan
            $activeShiftId = $shiftId;
            if (! $activeShiftId) {
                $activeShiftId = DB::table('shifts')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('status', 'open')
                    ->latest('id')
                    ->value('id');
            }

            // 5. Penagihan atau Pengembalian Finansial (TASK-101 / ACT-05)
            if ($priceDiff > 0) {
                // Pelanggan membayar selisih harga lebih mahal
                $tendered = $amountTendered !== null ? (int) round((float) $amountTendered) : $diffInt;
                if ($tendered < $diffInt) {
                    throw new InvalidArgumentException("Jumlah pembayaran (Rp {$tendered}) kurang dari tagihan selisih (Rp {$diffInt}).");
                }
                $change = max(0, $tendered - $diffInt);

                $paymentId = DB::table('payments')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'order_id' => $order->id,
                    'shift_id' => $activeShiftId,
                    'payment_method' => strtolower($paymentMethod),
                    'amount' => $diffInt,
                    'amount_tendered' => $tendered,
                    'change_amount' => $change,
                    'reference_number' => 'EXC-PAY-'.$order->id.'-'.time(),
                    'status' => 'captured',
                    'processed_by' => $userId,
                    'captured_at' => now(),
                    'idempotency_key' => 'exc-pay:'.$order->id.':'.$oldVariantId.':'.$newVariantId.':'.uniqid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (strtolower($paymentMethod) === 'cash' && $activeShiftId) {
                    DB::table('cash_drawer_movements')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'shift_id' => $activeShiftId,
                        'type' => 'cash_in',
                        'category' => 'correction',
                        'amount' => $diffInt,
                        'reason' => "Pembayaran selisih tukar guling varian #{$oldVariant->sku} -> #{$newVariant->sku}",
                        'receipt_reference' => $order->order_code ?? (string) $order->id,
                        'created_by' => $userId,
                        'idempotency_key' => 'cdm-exc:'.$order->id.':'.$oldVariantId.':'.uniqid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->financialOutboxDispatcher->recordOutboxEvent(
                    tenantId: $tenantId,
                    storeId: $storeId,
                    eventType: 'retail.variant_exchange_revenue',
                    sourceModule: 'retail',
                    sourceType: 'order',
                    sourceId: $order->id,
                    sourceNumber: $order->order_code,
                    idempotencyKey: 'outbox-exc-rev:'.$order->id.':'.$oldVariantId.':'.$newVariantId.':'.uniqid(),
                    payload: [
                        'order_id' => $order->id,
                        'order_code' => $order->order_code,
                        'old_variant_id' => $oldVariantId,
                        'new_variant_id' => $newVariantId,
                        'price_difference' => $diffInt,
                        'payment_id' => $paymentId,
                        'payment_method' => $paymentMethod,
                        'currency' => 'IDR',
                    ]
                );
            } elseif ($priceDiff < 0) {
                // Toko mengembalikan uang selisih (tukar ke barang lebih murah)
                $paymentId = DB::table('payments')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'order_id' => $order->id,
                    'shift_id' => $activeShiftId,
                    'payment_method' => strtolower($paymentMethod),
                    'amount' => $diffInt,
                    'amount_tendered' => $diffInt,
                    'change_amount' => 0,
                    'reference_number' => 'EXC-REF-'.$order->id.'-'.time(),
                    'status' => 'refunded',
                    'processed_by' => $userId,
                    'captured_at' => now(),
                    'idempotency_key' => 'exc-ref:'.$order->id.':'.$oldVariantId.':'.$newVariantId.':'.uniqid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (strtolower($paymentMethod) === 'cash' && $activeShiftId) {
                    DB::table('cash_drawer_movements')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'shift_id' => $activeShiftId,
                        'type' => 'cash_out',
                        'category' => 'refund',
                        'amount' => $diffInt,
                        'reason' => "Pengembalian selisih tukar guling varian #{$oldVariant->sku} -> #{$newVariant->sku}",
                        'receipt_reference' => $order->order_code ?? (string) $order->id,
                        'created_by' => $userId,
                        'idempotency_key' => 'cdm-ref:'.$order->id.':'.$oldVariantId.':'.uniqid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->financialOutboxDispatcher->recordOutboxEvent(
                    tenantId: $tenantId,
                    storeId: $storeId,
                    eventType: 'retail.variant_exchange_refund',
                    sourceModule: 'retail',
                    sourceType: 'order',
                    sourceId: $order->id,
                    sourceNumber: $order->order_code,
                    idempotencyKey: 'outbox-exc-ref:'.$order->id.':'.$oldVariantId.':'.$newVariantId.':'.uniqid(),
                    payload: [
                        'order_id' => $order->id,
                        'order_code' => $order->order_code,
                        'old_variant_id' => $oldVariantId,
                        'new_variant_id' => $newVariantId,
                        'refund_amount' => $diffInt,
                        'payment_id' => $paymentId,
                        'payment_method' => $paymentMethod,
                        'currency' => 'IDR',
                    ]
                );
            }

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'retail.variant_exchanged',
                userId: $userId,
                storeId: $storeId,
                subjectType: Order::class,
                subjectId: $order->id,
                description: "Tukar guling varian: {$oldVariant->variant_name} -> {$newVariant->variant_name} ({$quantity} unit, selisih: Rp ".number_format($priceDiff, 2).')',
                metadata: [
                    'order_id' => $order->id,
                    'old_variant_id' => $oldVariantId,
                    'new_variant_id' => $newVariantId,
                    'quantity' => $quantity,
                    'price_difference' => $priceDiff,
                    'payment_id' => $paymentId,
                ]
            );

            return [
                'order_id' => $order->id,
                'old_variant_id' => $oldVariantId,
                'new_variant_id' => $newVariantId,
                'quantity' => $quantity,
                'price_difference' => round($priceDiff, 2),
                'payment_id' => $paymentId,
                'status' => 'COMPLETED',
            ];
        });
    }

    /**
     * Facade method untuk kemudahan pemanggilan dari Livewire komponen.
     *
     * @return array{order_id: int, old_variant_id: int, new_variant_id: int, quantity: int, price_difference: float, status: string, payment_id: int|null}
     */
    public function processExchange(
        int $orderId,
        int $orderItemId,
        int $newVariantId,
        int $quantity,
        ?string $reason = null,
        string $paymentMethod = 'cash',
        float|int|null $amountTendered = null,
        ?int $shiftId = null
    ): array {
        $tenantId = (int) $this->tenantContext->getTenantId();
        $storeId = (int) $this->tenantContext->getStoreId();
        $userId = (int) (auth()->id() ?? 1);

        $orderItem = OrderItem::withoutGlobalScopes()
            ->where('order_id', $orderId)
            ->where('id', $orderItemId)
            ->firstOrFail();

        $oldVariantId = (int) ($orderItem->product_variant_id ?? 0);

        return $this->exchangeVariant(
            tenantId: $tenantId,
            storeId: $storeId,
            orderId: $orderId,
            oldVariantId: $oldVariantId,
            newVariantId: $newVariantId,
            quantity: $quantity,
            userId: $userId,
            reason: $reason,
            paymentMethod: $paymentMethod,
            amountTendered: $amountTendered,
            shiftId: $shiftId
        );
    }
}
