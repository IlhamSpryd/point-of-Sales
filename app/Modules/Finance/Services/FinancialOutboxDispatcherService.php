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
        $storeCreditAmount = '0.00';
        $loyaltyRedemptionAmount = '0.00';

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
            } elseif ($method === 'store_credit') {
                $storeCreditAmount = bcadd($storeCreditAmount, $amt, 2);
            } elseif (in_array($method, ['gift_card', 'loyalty_point', 'loyalty_points', 'loyalty'], true)) {
                $loyaltyRedemptionAmount = bcadd($loyaltyRedemptionAmount, $amt, 2);
            } else {
                $pgClearingAmount = bcadd($pgClearingAmount, $amt, 2);
            }
        }

        $toBc = fn ($val) => (is_numeric($val) ? bcadd((string) $val, '0', 2) : '0.00');

        $subtotal = $toBc($order->getSubtotal());
        $tax = $toBc($order->getTaxAmount());
        $total = $toBc($order->getTotalAmount());
        $discount = $toBc($order->getDiscountAmount());
        $serviceCharge = $toBc($order->getServiceChargeAmount());
        $roundingRaw = $order->getRoundingAdjustment();
        $roundingVal = is_numeric($roundingRaw) ? (float) $roundingRaw : 0.0;

        $roundingGainAmount = '0.00';
        $roundingLossAmount = '0.00';
        if ($roundingVal > 0) {
            $roundingGainAmount = bcadd((string) $roundingVal, '0', 2);
        } elseif ($roundingVal < 0) {
            $roundingLossAmount = bcadd((string) abs($roundingVal), '0', 2);
        }

        $payload = [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'accounting_date' => Carbon::parse($order->getCreatedAt() ?? now())->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'service_charge_amount' => $serviceCharge,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'cash_amount' => $cashAmount,
            'pg_clearing_amount' => $pgClearingAmount,
            'store_credit_amount' => $storeCreditAmount,
            'loyalty_redemption_amount' => $loyaltyRedemptionAmount,
            'rounding_gain_amount' => $roundingGainAmount,
            'rounding_loss_amount' => $roundingLossAmount,
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

        $discReversed = $orderReturn->getDiscountReversed();
        $scReturned = $orderReturn->getServiceChargeReturned();

        $accountingDate = Carbon::parse($orderReturn->getCompletedAt() ?? now())->toDateString();
        if ($orderReturn->getOrderId()) {
            $orderRow = DB::table('orders')->where('id', $orderReturn->getOrderId())->first();
            if ($orderRow && ! empty($orderRow->order_date)) {
                try {
                    app(AccountingPeriodService::class)->resolveOpenPeriodForDate($orderRow->order_date, (int) $orderReturn->getTenantId());
                } catch (\Throwable) {
                    $accountingDate = Carbon::parse($orderReturn->getCompletedAt() ?? now())->toDateString();
                }
            }
        }

        $payload = [
            'order_return_id' => $orderReturn->getId(),
            'return_number' => $orderReturn->getReturnNumber(),
            'order_id' => $orderReturn->getOrderId(),
            'order_number' => $orderCode,
            'accounting_date' => $accountingDate,
            'total_refund_amount' => bcadd((string) $orderReturn->getRefundAmount(), '0', 2),
            'subtotal_returned' => bcadd((string) $orderReturn->getSubtotalReturned(), '0', 2),
            'tax_returned' => bcadd((string) $orderReturn->getTaxReturned(), '0', 2),
            'discount_reversed' => bcadd((string) $discReversed, '0', 2),
            'service_charge_returned' => bcadd((string) $scReturned, '0', 2),
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
     * Emisi event outbox saat Order POS dibatalkan/void (pos.order.voided).
     */
    public function dispatchPosOrderVoided(PosOrderReaderInterface $order, ?string $accountingDate = null): FinancialEventOutbox
    {
        $orderId = $order->getId();
        $tenantId = $order->getTenantId();
        $storeId = $order->getStoreId();
        $orderNumber = $order->getOrderNumber();

        // 1. Ekstraksi COGS Retail & F&B
        $retailMovements = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $orderId)
            ->where('type', 'sale_deduction')
            ->get();

        $cogsRetail = '0.00';
        foreach ($retailMovements as $mov) {
            $qty = abs((float) $mov->quantity);
            $cost = (string) ($mov->product->standard_cost ?? $mov->product->cost_price ?? $mov->unit_cost ?? '0.00');
            $lineCost = bcmul(bc_num($qty), bc_num($cost), 2);
            $cogsRetail = bcadd($cogsRetail, $lineCost, 2);
        }

        $fnbMovements = IngredientStockMovement::withoutGlobalScopes()
            ->where('order_id', $orderId)
            ->get();

        $cogsFood = '0.00';
        foreach ($fnbMovements as $fnb) {
            $qty = abs((float) $fnb->quantity);
            $cost = (string) ($fnb->unit_cost ?? '0.00');
            $lineCost = bcmul((string) $qty, $cost, 2);
            $cogsFood = bcadd($cogsFood, $lineCost, 2);
        }

        $totalCogs = bcadd($cogsRetail, $cogsFood, 2);

        // 2. Ekstraksi Pembayaran
        $payments = DB::table('payments')
            ->where('order_id', $orderId)
            ->whereIn('status', ['captured', \App\Enums\PaymentStatusEnum::Captured->value])
            ->get();

        if ($payments->isEmpty()) {
            $payments = DB::table('payments')
                ->where('order_id', $orderId)
                ->whereIn('status', ['voided', \App\Enums\PaymentStatusEnum::Voided->value])
                ->get();
        }

        if ($payments->isEmpty()) {
            $payments = DB::table('payments')
                ->where('order_id', $orderId)
                ->get();
        }

        $paymentDetails = [];
        $cashAmount = '0.00';
        $pgClearingAmount = '0.00';
        $storeCreditAmount = '0.00';
        $loyaltyRedemptionAmount = '0.00';

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
            } elseif ($method === 'store_credit') {
                $storeCreditAmount = bcadd($storeCreditAmount, $amt, 2);
            } elseif (in_array($method, ['gift_card', 'loyalty_point', 'loyalty_points', 'loyalty'], true)) {
                $loyaltyRedemptionAmount = bcadd($loyaltyRedemptionAmount, $amt, 2);
            } else {
                $pgClearingAmount = bcadd($pgClearingAmount, $amt, 2);
            }
        }

        $toBc = fn ($val) => (is_numeric($val) ? bcadd((string) $val, '0', 2) : '0.00');

        $subtotal = $toBc($order->getSubtotal());
        $tax = $toBc($order->getTaxAmount());
        $total = $toBc($order->getTotalAmount());
        $discount = $toBc($order->getDiscountAmount());
        $serviceCharge = $toBc($order->getServiceChargeAmount());
        $roundingRaw = $order->getRoundingAdjustment();
        $roundingVal = is_numeric($roundingRaw) ? (float) $roundingRaw : 0.0;
        $roundingGainAmount = '0.00';
        $roundingLossAmount = '0.00';
        if ($roundingVal > 0) {
            $roundingGainAmount = bcadd((string) $roundingVal, '0', 2);
        } elseif ($roundingVal < 0) {
            $roundingLossAmount = bcadd((string) abs($roundingVal), '0', 2);
        }

        // 3. Tentukan accounting_date reversal (Koreksi #4)
        $resolvedDate = $accountingDate;
        if (! $resolvedDate) {
            $createdAt = $order->getCreatedAt();
            $origOrderDate = Carbon::parse($createdAt ?? now())->toDateString();
            try {
                app(AccountingPeriodService::class)->resolveOpenPeriodForDate($origOrderDate, $tenantId);
                $resolvedDate = $origOrderDate;
            } catch (\Throwable) {
                // Periode asal sudah HARD_CLOSED: gunakan tanggal kejadian reversal hari ini
                $resolvedDate = now()->toDateString();
            }
        }

        $payload = [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'accounting_date' => $resolvedDate,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'service_charge_amount' => $serviceCharge,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'cash_amount' => $cashAmount,
            'pg_clearing_amount' => $pgClearingAmount,
            'store_credit_amount' => $storeCreditAmount,
            'loyalty_redemption_amount' => $loyaltyRedemptionAmount,
            'rounding_gain_amount' => $roundingGainAmount,
            'rounding_loss_amount' => $roundingLossAmount,
            'cogs_retail' => $cogsRetail,
            'cogs_food' => $cogsFood,
            'total_cogs' => $totalCogs,
            'payments' => $paymentDetails,
        ];

        return $this->recordOutboxEvent(
            tenantId: $tenantId,
            storeId: $storeId,
            eventType: 'pos.order.voided',
            sourceModule: 'POS',
            sourceType: 'ORDER',
            sourceId: $orderId,
            sourceNumber: $orderNumber,
            idempotencyKey: "pos:order:voided:{$orderId}",
            payload: $payload,
            occurredAt: now()
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

    /**
     * Emisi event outbox saat Pesanan OFD (GrabFood/GoFood) selesai (ofd.order.completed).
     * Jurnal seimbang Rp 0,00:
     * Dr 1125 Piutang Kliring OFD = Gross + Tax - MerchantPromoSubsidy
     * Dr 4195 Subsidi Promo Merchant OFD = MerchantPromoSubsidy
     * Cr 4100 Pendapatan Penjualan = Gross
     * Cr 2200 Utang Pajak PB1/PPN = Tax
     * Dr 5200 Beban Komisi Platform OFD = Commission
     * Cr 1125 Piutang Kliring OFD = Commission
     */
    public function dispatchOfdOrderCompleted(
        PosOrderReaderInterface $order,
        float|string $commissionAmount = '0.00',
        float|string $merchantPromoSubsidy = '0.00'
    ): FinancialEventOutbox {
        $gross = bcadd((string) $order->getSubtotal(), '0', 2);
        $tax = bcadd((string) $order->getTaxAmount(), '0', 2);
        $promoSubsidy = bcadd((string) $merchantPromoSubsidy, '0', 2);
        $commission = bcadd((string) $commissionAmount, '0', 2);

        // Net piutang kliring sebelum komisi = Gross + Tax - PromoSubsidy
        $netReceivable = bcsub(bcadd($gross, $tax, 2), $promoSubsidy, 2);
        // Net payout yang akan dicairkan ke bank = NetReceivable - Commission
        $netPayout = bcsub($netReceivable, $commission, 2);

        $payload = [
            'order_id' => $order->getId(),
            'order_code' => $order->getOrderNumber(),
            'subtotal' => $gross,
            'tax_amount' => $tax,
            'merchant_promo_subsidy' => $promoSubsidy,
            'net_receivable_amount' => $netReceivable,
            'commission_amount' => $commission,
            'net_payout_amount' => $netPayout,
            'accounting_date' => Carbon::parse($order->getCreatedAt() ?? now())->toDateString(),
        ];

        return $this->recordOutboxEvent(
            tenantId: $order->getTenantId(),
            storeId: $order->getStoreId(),
            eventType: 'ofd.order.completed',
            sourceModule: 'OFD',
            sourceType: 'ORDER',
            sourceId: $order->getId(),
            sourceNumber: $order->getOrderNumber(),
            idempotencyKey: "ofd:order:completed:{$order->getId()}",
            payload: $payload,
            occurredAt: $order->getCreatedAt() ? Carbon::parse($order->getCreatedAt()) : now()
        );
    }

    /**
     * Emisi event outbox saat dana settlement OFD dicairkan ke rekening bank (ofd.payout.settled).
     * Jurnal:
     * Dr 1110 Bank Utama = settled_amount
     * Cr 1125 Piutang Kliring OFD = settled_amount
     * (Menolkan saldo akun 1125 tepat Rp 0,00!)
     */
    public function dispatchOfdPayoutSettled(
        int $tenantId,
        int $storeId,
        string $payoutReference,
        float|string $settledAmount
    ): FinancialEventOutbox {
        $amount = bcadd((string) $settledAmount, '0', 2);

        $payload = [
            'settled_amount' => $amount,
            'payout_reference' => $payoutReference,
            'accounting_date' => now()->toDateString(),
        ];

        return $this->recordOutboxEvent(
            tenantId: $tenantId,
            storeId: $storeId,
            eventType: 'ofd.payout.settled',
            sourceModule: 'OFD',
            sourceType: 'PAYOUT',
            sourceId: (int) crc32($payoutReference),
            sourceNumber: $payoutReference,
            idempotencyKey: "ofd:payout:settled:{$tenantId}:{$payoutReference}",
            payload: $payload,
            occurredAt: now()
        );
    }
}
