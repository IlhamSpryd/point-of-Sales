<?php

declare(strict_types=1);

namespace App\Modules\FNB\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SplitBillService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Pemecahan tagihan meja aktif menjadi sub-order terpisah berdasarkan pembagian item (Split By Item).
     *
     * @param array<int, array{sub_order_label?: string, items: array<int, array{order_item_id: int, quantity: int}>}> $splits
     * @return array<int, Order>
     *
     * @throws DomainException|InvalidArgumentException
     */
    public function splitByItem(Order $order, array $splits, int $userId): array
    {
        if (count($splits) < 2) {
            throw new InvalidArgumentException('Pemisahan tagihan minimal membutuhkan 2 sub-pesanan.');
        }

        return DB::transaction(function () use ($order, $splits, $userId) {
            $lockedOrder = Order::withoutGlobalScopes()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                throw new DomainException('Pesanan tidak ditemukan.');
            }

            if (in_array($lockedOrder->order_status, [OrderStatus::Paid, OrderStatus::Completed, OrderStatus::Void, OrderStatus::Cancelled], true)) {
                $statusVal = $lockedOrder->order_status->value;
                throw new DomainException("Pesanan #{$lockedOrder->order_code} tidak dapat di-split karena berstatus {$statusVal}.");
            }

            $orderItems = OrderItem::withoutGlobalScopes()
                ->where('order_id', $lockedOrder->id)
                ->get()
                ->keyBy('id');

            // Validasi kuantitas item yang di-split
            $consumedQtyPerItem = [];
            foreach ($splits as $split) {
                foreach ($split['items'] as $it) {
                    $itemId = (int) $it['order_item_id'];
                    $qty = (int) $it['quantity'];

                    if ($qty <= 0) {
                        throw new InvalidArgumentException('Kuantitas item pada split harus lebih besar dari 0.');
                    }

                    if (! $orderItems->has($itemId)) {
                        throw new DomainException("Item pesanan #{$itemId} tidak valid atau bukan bagian dari pesanan ini.");
                    }

                    $consumedQtyPerItem[$itemId] = ($consumedQtyPerItem[$itemId] ?? 0) + $qty;
                }
            }

            foreach ($orderItems as $itemId => $item) {
                $requestedQty = $consumedQtyPerItem[$itemId] ?? 0;
                if ($requestedQty !== (int) $item->qty) {
                    throw new DomainException(
                        "Kuantitas item \"{$item->product_name_snapshot}\" yang dialokasikan ({$requestedQty}) " .
                        "tidak sama dengan total pada pesanan ({$item->qty}). Seluruh item harus dialokasikan secara lengkap."
                    );
                }
            }

            $parentTotal = (int) $lockedOrder->order_amount;
            $parentSubtotal = (int) $lockedOrder->subtotal_amount;
            $createdSubOrders = [];
            $accumulatedTotalAllocated = 0;

            foreach ($splits as $idx => $split) {
                $isLast = ($idx === count($splits) - 1);
                $splitLabel = $split['sub_order_label'] ?? "Sub-Order #" . ($idx + 1);

                $splitSubtotal = 0;
                $splitItemsData = [];

                foreach ($split['items'] as $it) {
                    $originalItem = $orderItems->get((int) $it['order_item_id']);
                    if (! $originalItem) {
                        throw new DomainException("Item pesanan #{$it['order_item_id']} tidak ditemukan.");
                    }
                    $qty = (int) $it['quantity'];
                    $unitPrice = (int) $originalItem->order_price;
                    $lineSubtotal = $unitPrice * $qty;

                    $splitSubtotal += $lineSubtotal;
                    $splitItemsData[] = [
                        'original_item' => $originalItem,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $lineSubtotal,
                    ];
                }

                // Alokasi proporsional total tagihan
                $splitOrderAmount = 0;
                if ($isLast) {
                    // Penjaga paritas mutlak (T-FNB-02): Sub-order terakhir menyerap selisih pembulatan
                    $splitOrderAmount = $parentTotal - $accumulatedTotalAllocated;
                } else {
                    $ratio = $parentSubtotal > 0 ? ($splitSubtotal / $parentSubtotal) : (1 / count($splits));
                    $splitOrderAmount = (int) round($parentTotal * $ratio);
                    $accumulatedTotalAllocated += $splitOrderAmount;
                }

                $subOrderCode = $lockedOrder->order_code . '-S' . ($idx + 1);

                $subOrder = Order::create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'store_id' => $lockedOrder->store_id,
                    'user_id' => $userId,
                    'customer_id' => $lockedOrder->customer_id,
                    'table_id' => $lockedOrder->table_id,
                    'table_number' => $lockedOrder->table_number,
                    'order_type' => $lockedOrder->order_type,
                    'origin_channel' => $lockedOrder->origin_channel,
                    'source_reference' => $lockedOrder->order_code,
                    'order_code' => $subOrderCode,
                    'idempotency_key' => (string) Str::uuid(),
                    'order_date' => $lockedOrder->order_date ?? now()->toDateString(),
                    'subtotal_amount' => $splitSubtotal,
                    'order_amount' => $splitOrderAmount,
                    'order_status' => 'pending',
                ]);

                foreach ($splitItemsData as $itData) {
                    OrderItem::create([
                        'tenant_id' => $lockedOrder->tenant_id,
                        'store_id' => $lockedOrder->store_id,
                        'order_id' => $subOrder->id,
                        'product_id' => $itData['original_item']->product_id,
                        'product_variant_id' => $itData['original_item']->product_variant_id,
                        'product_name_snapshot' => $itData['original_item']->product_name_snapshot,
                        'sku_snapshot' => $itData['original_item']->sku_snapshot,
                        'qty' => $itData['qty'],
                        'order_price' => $itData['unit_price'],
                        'order_subtotal' => $itData['line_subtotal'],
                        'preparation_status' => 'pending',
                    ]);
                }

                $createdSubOrders[] = $subOrder;
            }

            // Tandai pesanan induk sebagai cancelled karena telah dipecah
            $lockedOrder->forceFill([
                'order_status' => \App\Enums\OrderStatus::Cancelled,
            ])->save();

            $this->auditTrail->log(
                tenantId: (int) $lockedOrder->tenant_id,
                action: 'fnb.order_split_by_item',
                userId: $userId,
                storeId: (int) $lockedOrder->store_id,
                subjectType: Order::class,
                subjectId: $lockedOrder->id,
                description: "Pesanan {$lockedOrder->order_code} dipecah (split by item) menjadi " . count($createdSubOrders) . " sub-pesanan.",
                metadata: [
                    'parent_order_id' => $lockedOrder->id,
                    'parent_order_amount' => $parentTotal,
                    'sub_orders_count' => count($createdSubOrders),
                ]
            );

            return $createdSubOrders;
        });
    }

    /**
     * Pemecahan tagihan meja aktif menjadi sub-order terpisah berdasarkan nominal (Split By Amount / Equal Split).
     *
     * @param array<int, int|float|string> $amounts
     * @return array<int, Order>
     *
     * @throws DomainException|InvalidArgumentException
     */
    public function splitByAmount(Order $order, array $amounts, int $userId): array
    {
        if (count($amounts) < 2) {
            throw new InvalidArgumentException('Pemisahan nominal minimal membutuhkan 2 bagian.');
        }

        return DB::transaction(function () use ($order, $amounts, $userId) {
            $lockedOrder = Order::withoutGlobalScopes()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                throw new DomainException('Pesanan tidak ditemukan.');
            }

            if (in_array($lockedOrder->order_status, [OrderStatus::Paid, OrderStatus::Completed, OrderStatus::Void, OrderStatus::Cancelled], true)) {
                $statusVal = $lockedOrder->order_status->value;
                throw new DomainException("Pesanan #{$lockedOrder->order_code} tidak dapat di-split karena berstatus {$statusVal}.");
            }

            $parentTotal = (int) $lockedOrder->order_amount;
            $intAmounts = [];
            $sumSplit = 0;

            foreach ($amounts as $amt) {
                $val = (int) round((float) $amt);
                if ($val <= 0) {
                    throw new InvalidArgumentException('Nominal pecahan tagihan harus lebih besar dari 0.');
                }
                $intAmounts[] = $val;
                $sumSplit += $val;
            }

            // Penegakan Paritas Total (T-FNB-02)
            if ($sumSplit !== $parentTotal) {
                throw new DomainException(
                    "Total nominal pemecahan tagihan (Rp " . number_format($sumSplit, 0, ',', '.') . 
                    ") tidak sama dengan total pesanan (Rp " . number_format($parentTotal, 0, ',', '.') . ")."
                );
            }

            $createdSubOrders = [];
            foreach ($intAmounts as $idx => $amt) {
                $subOrderCode = $lockedOrder->order_code . '-A' . ($idx + 1);

                $subOrder = Order::create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'store_id' => $lockedOrder->store_id,
                    'user_id' => $userId,
                    'customer_id' => $lockedOrder->customer_id,
                    'table_id' => $lockedOrder->table_id,
                    'table_number' => $lockedOrder->table_number,
                    'order_type' => $lockedOrder->order_type,
                    'origin_channel' => $lockedOrder->origin_channel,
                    'source_reference' => $lockedOrder->order_code,
                    'order_code' => $subOrderCode,
                    'idempotency_key' => (string) Str::uuid(),
                    'order_date' => $lockedOrder->order_date ?? now()->toDateString(),
                    'subtotal_amount' => $amt,
                    'order_amount' => $amt,
                    'order_status' => 'pending',
                ]);

                $createdSubOrders[] = $subOrder;
            }

            $lockedOrder->forceFill([
                'order_status' => \App\Enums\OrderStatus::Cancelled,
            ])->save();

            $this->auditTrail->log(
                tenantId: (int) $lockedOrder->tenant_id,
                action: 'fnb.order_split_by_amount',
                userId: $userId,
                storeId: (int) $lockedOrder->store_id,
                subjectType: Order::class,
                subjectId: $lockedOrder->id,
                description: "Pesanan {$lockedOrder->order_code} dipecah (split by amount) menjadi " . count($createdSubOrders) . " sub-pesanan.",
                metadata: [
                    'parent_order_id' => $lockedOrder->id,
                    'parent_order_amount' => $parentTotal,
                    'splits' => $intAmounts,
                ]
            );

            return $createdSubOrders;
        });
    }
}
