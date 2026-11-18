<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Jobs\ProcessFinancialOutboxJob;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Inventory\Models\IngredientStockMovement;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Contracts\OrderReturnReaderInterface;
use App\Modules\Sales\Contracts\PosOrderReaderInterface;
use App\Modules\Sales\Contracts\ShiftReaderInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinancialOutboxDispatcherService
{
    /**
     * Menulis rekaman outbox finansial ke tabel financial_event_outboxes di dalam transaksi yang sama.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordOutboxEvent(
        int $tenantId,
        ?int $storeId,
        string $eventType,
        string $sourceModule,
        string $sourceType,
        int $sourceId,
        ?string $sourceNumber,
        string $idempotencyKey,
        array $payload,
        ?CarbonInterface $occurredAt = null
    ): FinancialEventOutbox {
        $occurred = $occurredAt ?? now();

        $outbox = FinancialEventOutbox::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'store_id' => $storeId,
                'event_id' => 'EVT-'.strtoupper(str_replace('.', '-', $eventType)).'-'.$sourceId.'-'.time(),
                'event_type' => $eventType,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_number' => $sourceNumber,
                'payload' => $payload,
                'status' => 'PENDING',
                'occurred_at' => $occurred,
                'retry_count' => 0,
            ]
        );

        // Jika baru dibuat dan queue / sync diizinkan, dispatch job
        if ($outbox->wasRecentlyCreated) {
            if (config('finance.sync_posting', false)) {
                try {
                    app(PostingEngineService::class)->processOutboxEvent($outbox);
                } catch (\Throwable $e) {
                    Log::warning("Posting sync outbox #{$outbox->id} tertunda: ".$e->getMessage());
                }
            } else {
                ProcessFinancialOutboxJob::dispatch($outbox->id);
            }
        }

        return $outbox;
    }

    /**
     * Emisi event outbox saat Order POS berstatus Paid (pos.order.paid).
     */
    public function dispatchPosOrderPaid(PosOrderReaderInterface $order): FinancialEventOutbox
    {
        // 1. Ekstraksi COGS Retail (stock_movements sale_deduction)
        $retailMovements = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $order->getTenantId())
            ->where('order_id', $order->getId())
            ->where('type', 'sale_deduction')
            ->get();

        $cogsRetail = '0.00';
        foreach ($retailMovements as $mov) {
            $qty = abs((float) $mov->quantity);
            // Ambil standard_cost atau moving average cost dari product
            $cost = (string) ($mov->product?->standard_cost ?? $mov->product?->cost_price ?? $mov->unit_cost ?? '0.00');
            $lineCost = bcmul(bc_num($qty), bc_num($cost), 2);
            $cogsRetail = bcadd($cogsRetail, $lineCost, 2);
        }

        // 2. Ekstraksi COGS F&B (ingredient_stock_movements)
        $fnbMovements = IngredientStockMovement::withoutGlobalScopes()
            ->where('order_id', $order->getId())
            ->get();

        $cogsFood = '0.00';
        foreach ($fnbMovements as $fnb) {
            $qty = abs((float) $fnb->quantity);
            $cost = (string) ($fnb->unit_cost ?? '0.00');
            $lineCost = bcmul((string) $qty, $cost, 2);
            $cogsFood = bcadd($cogsFood, $lineCost, 2);
        }

        $totalCogs = bcadd($cogsRetail, $cogsFood, 2);

        // 3. Ekstraksi Pembayaran
        $payments = DB::table('payments')
            ->where('order_id', $order->getId())
            ->get();

        $paymentDetails = [];
        $cashAmount = '0.00';
        $pgClearingAmount = '0.00';

        foreach ($payments as $p) {
            $amt = bcadd((string) $p->amount, '0', 2);
            $rawPm = $p->payment_method ?? null;
            $method = (string) $rawPm;
            $paymentDetails[] = [
                'payment_id' => $p->id,
                'method' => $method,
                'amount' => $amt,
                'reference' => $p->reference_number ?? null,
            ];

            if ($method === 'cash') {
                $cashAmount = bcadd($cashAmount, $amt, 2);
            } else {
                $pgClearingAmount = bcadd($pgClearingAmount, $amt, 2);
            }
        }

        $toBc = fn ($val) => (is_numeric($val) ? bcadd((string) $val, '0', 2) : '0.00');

        $subtotal = $toBc($order->getSubtotal());
        $tax = $toBc($order->getTaxAmount());
        $total = $toBc($order->getTotalAmount());
        $discount = $toBc($order->getDiscountAmount());

        $payload = [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'accounting_date' => Carbon::parse($order->getCreatedAt() ?? now())->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'cash_amount' => $cashAmount,
            'pg_clearing_amount' => $pgClearingAmount,
            'cogs_retail' => $cogsRetail,
            'cogs_food' => $cogsFood,
            'total_cogs' => $totalCogs,
            'payments' => $paymentDetails,
        ];

        return $this->recordOutboxEvent(
            tenantId: $order->getTenantId(),
            storeId: $order->getStoreId(),
            eventType: 'pos.order.paid',
            sourceModule: 'POS',
            sourceType: 'ORDER',
            sourceId: $order->getId(),
            sourceNumber: $order->getOrderNumber(),
            idempotencyKey: "pos:order:paid:{$order->getId()}",
            payload: $payload,
            occurredAt: $order->getCreatedAt() ? Carbon::parse($order->getCreatedAt()) : now()
        );
    }

    /**
     * Emisi event outbox saat Retur & Refund POS selesai (sales.return.refunded).
     */
    public function dispatchSalesReturnRefunded(OrderReturnReaderInterface $orderReturn): FinancialEventOutbox
    {
        $returnItems = DB::table('order_return_items')
            ->where('order_return_id', $orderReturn->getId())
            ->get();

        $restockCogs = '0.00';
        $wasteCogs = '0.00';

        foreach ($returnItems as $item) {
            $product = DB::table('products')->where('id', $item->product_id)->first();
            $cost = (string) ($product?->standard_cost ?? $product?->cost_price ?? '0.00');
            $lineCost = bcmul(bc_num((float) $item->quantity_returned), bc_num($cost), 2);

            $rawDisposition = $item->disposition ?? null;
            $isRestock = $rawDisposition === 'restock';

            if ($isRestock) {
                $restockCogs = bcadd($restockCogs, $lineCost, 2);
            } else {
                $wasteCogs = bcadd($wasteCogs, $lineCost, 2);
            }
        }

        $orderCode = null;
        if ($orderReturn->getOrderId()) {
            $orderRow = DB::table('orders')->where('id', $orderReturn->getOrderId())->first();
            $orderCode = $orderRow ? (string) ($orderRow->order_code ?? '') : null;
        }

        $payload = [
            'order_return_id' => $orderReturn->getId(),
            'return_number' => $orderReturn->getReturnNumber(),
            'order_id' => $orderReturn->getOrderId(),
            'order_number' => $orderCode,
            'accounting_date' => Carbon::parse($orderReturn->getCompletedAt() ?? now())->toDateString(),
            'total_refund_amount' => bcadd((string) $orderReturn->getRefundAmount(), '0', 2),
            'subtotal_returned' => bcadd((string) $orderReturn->getSubtotalReturned(), '0', 2),
            'tax_returned' => bcadd((string) $orderReturn->getTaxReturned(), '0', 2),
            'restock_cogs' => $restockCogs,
            'waste_cogs' => $wasteCogs,
        ];

        return $this->recordOutboxEvent(
            tenantId: $orderReturn->getTenantId(),
            storeId: $orderReturn->getStoreId(),
            eventType: 'sales.return.refunded',
            sourceModule: 'SALES',
            sourceType: 'ORDER_RETURN',
            sourceId: $orderReturn->getId(),
            sourceNumber: $orderReturn->getReturnNumber(),
            idempotencyKey: "sales:return:refunded:{$orderReturn->getId()}",
            payload: $payload,
            occurredAt: $orderReturn->getCreatedAt() ? Carbon::parse($orderReturn->getCreatedAt()) : now()
        );
    }

    /**
     * Emisi event outbox saat Shift Kasir ditutup dengan selisih kas (pos.shift.closed).
     */
    public function dispatchShiftClosed(ShiftReaderInterface $shift): ?FinancialEventOutbox
    {
        $cashDifference = (int) $shift->getCashDifference();

        // Jika selisih kas 0.00, tidak memerlukan jurnal selisih kas (no-op)
        if ($cashDifference === 0) {
            return null;
        }

        $diffDec = bcadd((string) abs($cashDifference), '0', 2);
        $isOver = $cashDifference > 0;
        $overageAmount = $isOver ? $diffDec : '0.00';
        $shortageAmount = ! $isOver ? $diffDec : '0.00';

        $payload = [
            'shift_id' => $shift->getId(),
            'accounting_date' => Carbon::parse($shift->getClosedAt() ?? now())->toDateString(),
            'expected_cash' => bcadd((string) $shift->getExpectedCash(), '0', 2),
            'closing_balance' => bcadd((string) $shift->getClosingBalance(), '0', 2),
            'cash_difference' => (string) $cashDifference,
            'difference_amount' => $diffDec,
            'overage_amount' => $overageAmount,
            'shortage_amount' => $shortageAmount,
            'is_over' => $isOver,
        ];

        return $this->recordOutboxEvent(
            tenantId: $shift->getTenantId(),
            storeId: $shift->getStoreId(),
            eventType: 'pos.shift.closed',
            sourceModule: 'POS',
            sourceType: 'SHIFT',
            sourceId: $shift->getId(),
            sourceNumber: $shift->getShiftNumber() ?? ('SHIFT-'.$shift->getId()),
            idempotencyKey: "pos:shift:closed:{$shift->getId()}",
            payload: $payload,
            occurredAt: $shift->getClosedAt() ? Carbon::parse($shift->getClosedAt()) : now()
        );
    }
}
