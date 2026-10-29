<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\IngredientStockMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Modules\Finance\Jobs\ProcessFinancialOutboxJob;
use App\Modules\Finance\Models\FinancialEventOutbox;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class FinancialOutboxDispatcherService
{
    /**
     * Menulis rekaman outbox finansial ke tabel financial_event_outboxes di dalam transaksi yang sama.
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
    public function dispatchPosOrderPaid(Order $order): FinancialEventOutbox
    {
        // 1. Ekstraksi COGS Retail (stock_movements sale_deduction)
        $retailMovements = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->where('type', 'sale_deduction')
            ->get();

        $cogsRetail = '0.00';
        foreach ($retailMovements as $mov) {
            $qty = abs((float) $mov->quantity);
            // Ambil standard_cost atau moving average cost dari product
            $cost = (string) ($mov->product?->standard_cost ?? $mov->product?->cost_price ?? $mov->unit_cost ?? '0.00');
            $lineCost = bcmul((string) $qty, $cost, 2);
            $cogsRetail = bcadd($cogsRetail, $lineCost, 2);
        }

        // 2. Ekstraksi COGS F&B (ingredient_stock_movements)
        $fnbMovements = IngredientStockMovement::withoutGlobalScopes()
            ->where('order_id', $order->id)
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
        $payments = Payment::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->get();

        $paymentDetails = [];
        $cashAmount = '0.00';
        $pgClearingAmount = '0.00';

        foreach ($payments as $p) {
            $amt = bcadd((string) $p->amount, '0', 2);
            $method = (string) ($p->payment_method->value ?? $p->payment_method);
            $paymentDetails[] = [
                'payment_id' => $p->id,
                'method' => $method,
                'amount' => $amt,
                'reference' => $p->reference_number,
            ];

            if ($method === 'cash') {
                $cashAmount = bcadd($cashAmount, $amt, 2);
            } else {
                $pgClearingAmount = bcadd($pgClearingAmount, $amt, 2);
            }
        }

        $toBc = fn ($val) => (is_numeric($val) ? bcadd((string) $val, '0', 2) : '0.00');

        $subtotal = $toBc($order->subtotal_amount ?? $order->subtotal ?? 0);
        $tax = $toBc($order->tax_amount ?? 0);
        $total = $toBc($order->order_amount ?? $order->total_amount ?? 0);
        $discount = $toBc($order->discount_amount ?? 0);

        $payload = [
            'order_id' => $order->id,
            'order_number' => $order->order_code ?? (string) $order->id,
            'accounting_date' => Carbon::parse($order->created_at)->toDateString(),
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
            tenantId: (int) $order->tenant_id,
            storeId: $order->store_id ? (int) $order->store_id : null,
            eventType: 'pos.order.paid',
            sourceModule: 'POS',
            sourceType: 'ORDER',
            sourceId: $order->id,
            sourceNumber: $order->order_code ?? (string) $order->id,
            idempotencyKey: "pos:order:paid:{$order->id}",
            payload: $payload,
            occurredAt: $order->created_at ? Carbon::parse($order->created_at) : now()
        );
    }

    /**
     * Emisi event outbox saat Retur & Refund POS selesai (sales.return.refunded).
     */
    public function dispatchSalesReturnRefunded(OrderReturn $orderReturn): FinancialEventOutbox
    {
        $order = $orderReturn->order;
        $returnItems = $orderReturn->returnItems()->with('product')->get();

        $restockCogs = '0.00';
        $wasteCogs = '0.00';

        foreach ($returnItems as $item) {
            $cost = (string) ($item->product?->standard_cost ?? $item->product?->cost_price ?? '0.00');
            $lineCost = bcmul((string) $item->quantity_returned, $cost, 2);

            $isRestock = false;
            if (is_object($item->disposition) && method_exists($item->disposition, 'isRestock')) {
                $isRestock = $item->disposition->isRestock();
            } elseif (is_string($item->disposition)) {
                $isRestock = $item->disposition === 'restock';
            } elseif (is_object($item->disposition) && property_exists($item->disposition, 'value')) {
                $isRestock = $item->disposition->value === 'restock';
            }

            if ($isRestock) {
                $restockCogs = bcadd($restockCogs, $lineCost, 2);
            } else {
                $wasteCogs = bcadd($wasteCogs, $lineCost, 2);
            }
        }

        $payload = [
            'order_return_id' => $orderReturn->id,
            'return_number' => $orderReturn->return_number,
            'order_id' => $orderReturn->order_id,
            'order_number' => $order?->order_code,
            'accounting_date' => Carbon::parse($orderReturn->completed_at ?? now())->toDateString(),
            'total_refund_amount' => bcadd((string) $orderReturn->total_refundable_amount, '0', 2),
            'subtotal_returned' => bcadd((string) $orderReturn->subtotal_returned, '0', 2),
            'tax_returned' => bcadd((string) $orderReturn->tax_returned, '0', 2),
            'restock_cogs' => $restockCogs,
            'waste_cogs' => $wasteCogs,
        ];

        return $this->recordOutboxEvent(
            tenantId: (int) $orderReturn->tenant_id,
            storeId: $orderReturn->store_id ? (int) $orderReturn->store_id : null,
            eventType: 'sales.return.refunded',
            sourceModule: 'SALES',
            sourceType: 'ORDER_RETURN',
            sourceId: $orderReturn->id,
            sourceNumber: $orderReturn->return_number,
            idempotencyKey: "sales:return:refunded:{$orderReturn->id}",
            payload: $payload,
            occurredAt: $orderReturn->completed_at ? Carbon::parse($orderReturn->completed_at) : now()
        );
    }

    /**
     * Emisi event outbox saat Shift Kasir ditutup dengan selisih kas (pos.shift.closed).
     */
    public function dispatchShiftClosed(Shift $shift): ?FinancialEventOutbox
    {
        $cashDifference = (int) ($shift->cash_difference ?? 0);

        // Jika selisih kas 0.00, tidak memerlukan jurnal selisih kas (no-op)
        if ($cashDifference === 0) {
            return null;
        }

        $diffDec = bcadd((string) abs($cashDifference), '0', 2);
        $isOver = $cashDifference > 0;
        $overageAmount = $isOver ? $diffDec : '0.00';
        $shortageAmount = ! $isOver ? $diffDec : '0.00';

        $payload = [
            'shift_id' => $shift->id,
            'accounting_date' => Carbon::parse($shift->closed_at ?? now())->toDateString(),
            'expected_cash' => bcadd((string) ($shift->expected_cash ?? 0), '0', 2),
            'closing_balance' => bcadd((string) ($shift->closing_balance ?? 0), '0', 2),
            'cash_difference' => (string) $cashDifference,
            'difference_amount' => $diffDec,
            'overage_amount' => $overageAmount,
            'shortage_amount' => $shortageAmount,
            'is_over' => $isOver,
        ];

        return $this->recordOutboxEvent(
            tenantId: (int) $shift->tenant_id,
            storeId: $shift->store_id ? (int) $shift->store_id : null,
            eventType: 'pos.shift.closed',
            sourceModule: 'POS',
            sourceType: 'SHIFT',
            sourceId: $shift->id,
            sourceNumber: 'SHIFT-'.$shift->id,
            idempotencyKey: "pos:shift:closed:{$shift->id}",
            payload: $payload,
            occurredAt: $shift->closed_at ? Carbon::parse($shift->closed_at) : now()
        );
    }
}
