<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Enums\OrderStatus;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderReturnService
{
    public function __construct(
        protected ReturnRefundService $returnRefundService,
        protected TenantContext $tenantContext,
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Memproses retur kasir (parsial maupun penuh) dengan verifikasi otorisasi supervisor,
     * pemulihan stok barang, dan pencatatan riwayat pada order_returns & payment_refunds.
     *
     * Format $items:
     * [
     *   [
     *     'order_item_id' => 123,
     *     'quantity' => 2,
     *     'disposition' => 'RESTOCK'|'SCRAP'|'REPAIR',
     *     'reason' => 'Barang rusak'
     *   ],
     *   ...
     * ]
     *
     * @param  array<int, array{order_item_id: int, quantity: int, disposition?: string, reason?: string}>  $items
     *
     * @throws DomainException|ValidationException
     */
    public function processReturn(
        int $tenantId,
        int $storeId,
        int $orderId,
        array $items,
        int $cashierUserId,
        string $supervisorPin,
        string $refundMethod = 'CASH',
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): OrderReturn {
        if (empty($items)) {
            throw new DomainException('Returned items list cannot be empty.');
        }

        $order = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            throw new DomainException('Order not found in this branch.');
        }

        if (! in_array($order->order_status, [OrderStatus::Paid, OrderStatus::Completed], true)) {
            $statusStr = $order->order_status->value;
            throw new DomainException("Only fully paid orders can be returned. Current order status: {$statusStr}.");
        }

        // Resolusi shift kasir aktif
        $shiftId = (int) ($order->shift_id ?? 0);
        if ($shiftId <= 0) {
            $openShift = Shift::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('status', 'open')
                ->first();
            $shiftId = $openShift ? (int) $openShift->id : 1;
        }

        // Resolusi device
        $deviceId = (int) ($order->created_by_device_id ?? 0);
        if ($deviceId <= 0) {
            $device = Device::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->first();
            $deviceId = $device ? (int) $device->id : 1;
        }

        $returnReason = $reason ?: 'Cashier return for order '.$order->order_code;
        $idempotency = $idempotencyKey ?: (string) Str::uuid();

        // 1. Validasi item pesanan dan hitung total estimasi refund
        $returnItemsWithDisposition = [];
        $totalEstimatedRefund = 0;

        foreach ($items as $item) {
            $orderItemId = (int) $item['order_item_id'];
            $qty = (int) $item['quantity'];
            $disposition = strtoupper(trim((string) ($item['disposition'] ?? 'RESTOCK')));
            $itemReason = (string) ($item['reason'] ?? $returnReason);

            if ($qty <= 0) {
                throw new DomainException('Returned item quantity must be greater than 0.');
            }

            $orderItem = OrderItem::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->where('id', $orderItemId)
                ->first();

            if (! $orderItem) {
                throw new DomainException("Order item #{$orderItemId} not found in this order.");
            }

            if ($qty > (int) $orderItem->qty) {
                throw new DomainException("Return quantity ({$qty}) exceeds purchased quantity ({$orderItem->qty}) for product #{$orderItem->product_id}.");
            }

            $itemSubtotal = ((int) $orderItem->order_price) * $qty;
            $totalEstimatedRefund += $itemSubtotal;

            $returnItemsWithDisposition[] = [
                'order_item_id' => $orderItemId,
                'quantity' => $qty,
                'disposition' => $disposition,
                'disposition_reason' => $itemReason,
            ];
        }

        // 2. Alokasikan refund ke pembayaran captured sebelumnya
        $capturedPayments = Payment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->whereIn('status', ['captured', 'completed'])
            ->get();

        if ($capturedPayments->isEmpty()) {
            throw new DomainException('No captured payment records found for this order.');
        }

        $refundAllocations = [];
        $remainingToRefund = $totalEstimatedRefund;

        foreach ($capturedPayments as $payment) {
            if ($remainingToRefund <= 0) {
                break;
            }

            $payableOnThisPayment = min((int) $payment->amount, $remainingToRefund);
            if ($payableOnThisPayment > 0) {
                $refundAllocations[] = [
                    'payment_id' => $payment->id,
                    'amount' => $payableOnThisPayment,
                    'refund_method' => $refundMethod,
                    'reason' => $returnReason,
                ];
                $remainingToRefund -= $payableOnThisPayment;
            }
        }

        // 3. Eksekusi alur retur melalui ReturnRefundService
        return $this->returnRefundService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: $returnItemsWithDisposition,
            refundAllocations: $refundAllocations,
            shiftId: $shiftId,
            deviceId: $deviceId,
            actorUserId: $cashierUserId,
            supervisorPin: $supervisorPin,
            reason: $returnReason,
            idempotencyKey: $idempotency
        );
    }
}
