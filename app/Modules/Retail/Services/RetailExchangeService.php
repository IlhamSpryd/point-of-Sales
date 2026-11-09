<?php

declare(strict_types=1);

namespace App\Modules\Retail\Services;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
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
        protected TenantContext $tenantContext
    ) {}

    /**
     * Alur tukar guling ukuran/varian ritel (Variant Exchange).
     *
     * Invariant:
     * 1. Retur varian lama ke stok karantina (inbound movement).
     * 2. Potong varian baru dari stok cabang (outbound movement).
     * 3. Jika harga varian sama, selisih bayar = 0.
     *
     * @return array{order_id: int, old_variant_id: int, new_variant_id: int, quantity: int, price_difference: float, status: string}
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
        ?string $reason = null
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Kuantitas tukar guling harus lebih dari 0.');
        }

        if ($oldVariantId === $newVariantId) {
            throw new InvalidArgumentException('Varian pengganti tidak boleh sama dengan varian yang ditukar.');
        }

        return DB::transaction(function () use (
            $tenantId, $storeId, $orderId, $oldVariantId, $newVariantId, $quantity, $userId, $reason
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
                userId: $userId
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
                userId: $userId
            );

            // 4. Hitung Selisih Finansial
            $oldUnitPrice = (float) ($oldVariant->price_override ?? $oldProductPrice);
            $newUnitPrice = (float) ($newVariant->price_override ?? $newProductPrice);
            $priceDiff = ($newUnitPrice - $oldUnitPrice) * $quantity;

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
                ]
            );

            return [
                'order_id' => $order->id,
                'old_variant_id' => $oldVariantId,
                'new_variant_id' => $newVariantId,
                'quantity' => $quantity,
                'price_difference' => round($priceDiff, 2),
                'status' => 'COMPLETED',
            ];
        });
    }

    /**
     * Facade method untuk kemudahan pemanggilan dari Livewire komponen.
     *
     * @return array{order_id: int, old_variant_id: int, new_variant_id: int, quantity: int, price_difference: float, status: string}
     */
    public function processExchange(
        int $orderId,
        int $orderItemId,
        int $newVariantId,
        int $quantity,
        ?string $reason = null
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
            reason: $reason
        );
    }
}
