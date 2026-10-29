<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use Carbon\Carbon;

class InventoryAccountingService
{
    public function __construct(
        protected FinancialOutboxDispatcherService $dispatcher
    ) {}

    /**
     * Emisi outbox saat Goods Received Note (GRN) diposting (purchasing.grn.posted).
     * Akuntansi PR-PUR-01: Debit Persediaan (Retail/Bahan Baku), Credit GR/IR Clearing.
     */
    public function recordGrnPosted(GoodsReceivedNote $grn): FinancialEventOutbox
    {
        $lines = $grn->lines()->get();
        $totalValuation = '0.00';
        $retailAmount = '0.00';
        $ingredientAmount = '0.00';

        foreach ($lines as $line) {
            $qty = (string) ($line->accepted_qty ?? $line->base_accepted_qty ?? $line->accepted_quantity ?? $line->received_quantity ?? 0);
            $cost = (string) ($line->effective_unit_cost ?? $line->unit_cost_snapshot ?? $line->unit_price ?? $line->unit_cost ?? 0);
            $lineTotal = bcmul($qty, $cost, 2);
            $totalValuation = bcadd($totalValuation, $lineTotal, 2);

            $isIngredient = ($line->purchasable_type === 'ingredient' || $line->item_type === 'ingredient' || ! empty($line->ingredient_id));
            if ($isIngredient) {
                $ingredientAmount = bcadd($ingredientAmount, $lineTotal, 2);
            } else {
                $retailAmount = bcadd($retailAmount, $lineTotal, 2);
            }
        }

        $payload = [
            'grn_id' => $grn->id,
            'grn_number' => $grn->grn_number,
            'purchase_order_id' => $grn->purchase_order_id,
            'supplier_id' => $grn->supplier_id,
            'accounting_date' => Carbon::parse($grn->received_date ?? now())->toDateString(),
            'total_amount' => $totalValuation,
            'retail_amount' => $retailAmount,
            'ingredient_amount' => $ingredientAmount,
            'gr_ir_clearing_amount' => $totalValuation,
        ];

        return $this->dispatcher->recordOutboxEvent(
            tenantId: (int) $grn->tenant_id,
            storeId: $grn->store_id ? (int) $grn->store_id : null,
            eventType: 'purchasing.grn.posted',
            sourceModule: 'PURCHASING',
            sourceType: 'GOODS_RECEIVED_NOTE',
            sourceId: $grn->id,
            sourceNumber: $grn->grn_number,
            idempotencyKey: "purchasing:grn:posted:{$grn->id}",
            payload: $payload,
            occurredAt: $grn->received_date ? Carbon::parse($grn->received_date) : now()
        );
    }

    /**
     * Emisi outbox saat Goods Received Note (GRN) dibatalkan/reversed (purchasing.grn.reversed).
     * Akuntansi PR-PUR-02: Debit GR/IR Clearing, Credit Persediaan.
     */
    public function recordGrnReversed(GoodsReceivedNote $grn, string $reason): FinancialEventOutbox
    {
        $lines = $grn->lines()->get();
        $totalValuation = '0.00';
        $retailAmount = '0.00';
        $ingredientAmount = '0.00';

        foreach ($lines as $line) {
            $qty = (string) ($line->accepted_qty ?? $line->base_accepted_qty ?? $line->accepted_quantity ?? $line->received_quantity ?? 0);
            $cost = (string) ($line->effective_unit_cost ?? $line->unit_cost_snapshot ?? $line->unit_price ?? $line->unit_cost ?? 0);
            $lineTotal = bcmul($qty, $cost, 2);
            $totalValuation = bcadd($totalValuation, $lineTotal, 2);

            $isIngredient = ($line->purchasable_type === 'ingredient' || $line->item_type === 'ingredient' || ! empty($line->ingredient_id));
            if ($isIngredient) {
                $ingredientAmount = bcadd($ingredientAmount, $lineTotal, 2);
            } else {
                $retailAmount = bcadd($retailAmount, $lineTotal, 2);
            }
        }

        $payload = [
            'grn_id' => $grn->id,
            'grn_number' => $grn->grn_number,
            'accounting_date' => now()->toDateString(),
            'total_amount' => $totalValuation,
            'retail_amount' => $retailAmount,
            'ingredient_amount' => $ingredientAmount,
            'gr_ir_clearing_amount' => $totalValuation,
            'reversal_reason' => $reason,
        ];

        return $this->dispatcher->recordOutboxEvent(
            tenantId: (int) $grn->tenant_id,
            storeId: $grn->store_id ? (int) $grn->store_id : null,
            eventType: 'purchasing.grn.reversed',
            sourceModule: 'PURCHASING',
            sourceType: 'GOODS_RECEIVED_NOTE_REVERSAL',
            sourceId: $grn->id,
            sourceNumber: $grn->grn_number,
            idempotencyKey: "purchasing:grn:reversed:{$grn->id}-".time(),
            payload: $payload,
            occurredAt: now()
        );
    }

    /**
     * Emisi outbox saat Stock Adjustment disetujui (inventory.adjustment.posted).
     * Akuntansi PR-INV-01:
     * - Stok Berkurang (Out): Dr Beban Waste/Kerugian / Cr Persediaan
     * - Stok Bertambah (In): Dr Persediaan / Cr Pendapatan/Koreksi Penyesuaian Persediaan
     */
    public function recordStockAdjustmentPosted(StockAdjustment $adjustment): FinancialEventOutbox
    {
        $items = $adjustment->items()->get();
        $totalPositiveAmount = '0.00';
        $totalNegativeAmount = '0.00';
        $positiveRetailAmount = '0.00';
        $negativeRetailAmount = '0.00';
        $positiveIngredientAmount = '0.00';
        $negativeIngredientAmount = '0.00';

        foreach ($items as $item) {
            $qty = (string) ($item->quantity ?? $item->adjusted_quantity ?? 0);
            $cost = (string) ($item->unit_cost_snapshot ?? $item->unit_cost ?? 0);
            $lineVal = bcmul(bcadd((string) abs((float) $qty), '0', 4), $cost, 2);

            $direction = strtoupper((string) ($item->direction ?? 'OUT'));
            $isIngredient = ($item->item_type === 'ingredient' || ! empty($item->ingredient_id));

            if ($direction === 'IN' || (isset($item->adjusted_quantity) && bccomp((string) $item->adjusted_quantity, '0', 4) > 0)) {
                if ($isIngredient) {
                    $positiveIngredientAmount = bcadd($positiveIngredientAmount, $lineVal, 2);
                } else {
                    $positiveRetailAmount = bcadd($positiveRetailAmount, $lineVal, 2);
                }
                $totalPositiveAmount = bcadd($totalPositiveAmount, $lineVal, 2);
            } else {
                if ($isIngredient) {
                    $negativeIngredientAmount = bcadd($negativeIngredientAmount, $lineVal, 2);
                } else {
                    $negativeRetailAmount = bcadd($negativeRetailAmount, $lineVal, 2);
                }
                $totalNegativeAmount = bcadd($totalNegativeAmount, $lineVal, 2);
            }
        }

        $reason = is_object($adjustment->reason_code)
            ? $adjustment->reason_code->value
            : ($adjustment->reason_code ?? $adjustment->reason ?? 'STOCK_ADJUSTMENT');

        $payload = [
            'adjustment_id' => $adjustment->id,
            'adjustment_number' => $adjustment->adjustment_number,
            'accounting_date' => Carbon::parse($adjustment->adjusted_at ?? $adjustment->adjustment_date ?? now())->toDateString(),
            'positive_amount' => $totalPositiveAmount,
            'negative_amount' => $totalNegativeAmount,
            'positive_retail_amount' => $positiveRetailAmount,
            'negative_retail_amount' => $negativeRetailAmount,
            'positive_ingredient_amount' => $positiveIngredientAmount,
            'negative_ingredient_amount' => $negativeIngredientAmount,
            'reason' => $reason,
        ];

        return $this->dispatcher->recordOutboxEvent(
            tenantId: (int) $adjustment->tenant_id,
            storeId: (int) $adjustment->store_id,
            eventType: 'inventory.adjustment.posted',
            sourceModule: 'INVENTORY',
            sourceType: 'STOCK_ADJUSTMENT',
            sourceId: $adjustment->id,
            sourceNumber: $adjustment->adjustment_number,
            idempotencyKey: "inventory:adjustment:posted:{$adjustment->id}",
            payload: $payload,
            occurredAt: $adjustment->adjusted_at ? Carbon::parse($adjustment->adjusted_at) : now()
        );
    }

    /**
     * Emisi outbox saat Stock Opname disetujui (inventory.opname.finalized).
     */
    public function recordStockOpnameApproved(StockOpname $opname): FinancialEventOutbox
    {
        $items = $opname->items()->get();
        $totalPositiveAmount = '0.00';
        $totalNegativeAmount = '0.00';
        $positiveRetailAmount = '0.00';
        $negativeRetailAmount = '0.00';
        $positiveIngredientAmount = '0.00';
        $negativeIngredientAmount = '0.00';

        foreach ($items as $item) {
            $diffQty = (string) ($item->discrepancy_quantity ?? 0);
            $cost = (string) ($item->unit_cost ?? $item->unit_cost_snapshot ?? 0);
            $lineVal = bcmul(bcadd((string) abs((float) $diffQty), '0', 4), $cost, 2);

            $isIngredient = ($item->item_type === 'ingredient' || ! empty($item->ingredient_id));

            if (bccomp($diffQty, '0.00', 4) > 0) {
                if ($isIngredient) {
                    $positiveIngredientAmount = bcadd($positiveIngredientAmount, $lineVal, 2);
                } else {
                    $positiveRetailAmount = bcadd($positiveRetailAmount, $lineVal, 2);
                }
                $totalPositiveAmount = bcadd($totalPositiveAmount, $lineVal, 2);
            } elseif (bccomp($diffQty, '0.00', 4) < 0) {
                if ($isIngredient) {
                    $negativeIngredientAmount = bcadd($negativeIngredientAmount, $lineVal, 2);
                } else {
                    $negativeRetailAmount = bcadd($negativeRetailAmount, $lineVal, 2);
                }
                $totalNegativeAmount = bcadd($totalNegativeAmount, $lineVal, 2);
            }
        }

        $payload = [
            'opname_id' => $opname->id,
            'opname_number' => $opname->opname_number,
            'accounting_date' => Carbon::parse($opname->approved_at ?? now())->toDateString(),
            'positive_amount' => $totalPositiveAmount,
            'negative_amount' => $totalNegativeAmount,
            'positive_retail_amount' => $positiveRetailAmount,
            'negative_retail_amount' => $negativeRetailAmount,
            'positive_ingredient_amount' => $positiveIngredientAmount,
            'negative_ingredient_amount' => $negativeIngredientAmount,
            'reason' => 'OPNAME_VARIANCE',
        ];

        return $this->dispatcher->recordOutboxEvent(
            tenantId: (int) $opname->tenant_id,
            storeId: (int) $opname->store_id,
            eventType: 'inventory.opname.finalized',
            sourceModule: 'INVENTORY',
            sourceType: 'STOCK_OPNAME',
            sourceId: $opname->id,
            sourceNumber: $opname->opname_number,
            idempotencyKey: "inventory:opname:finalized:{$opname->id}",
            payload: $payload,
            occurredAt: $opname->approved_at ? Carbon::parse($opname->approved_at) : now()
        );
    }

    /**
     * Emisi outbox saat Stock Transfer dikirim (inventory.transfer.shipped).
     * Akuntansi PR-INV-02:
     * Toko Asal: Dr Persediaan Dalam Perjalanan (In-Transit) / Cr Persediaan
     */
    public function recordStockTransferShipped(StockTransfer $transfer): FinancialEventOutbox
    {
        $items = $transfer->items()->get();
        $totalTransferValue = '0.00';
        $retailTransferValue = '0.00';
        $ingredientTransferValue = '0.00';

        foreach ($items as $item) {
            $qty = (string) ($item->quantity_transferred ?? $item->quantity_shipped ?? $item->quantity ?? 0);
            $cost = (string) ($item->carrying_unit_cost ?? $item->unit_cost ?? 0);
            $lineTotal = bcmul($qty, $cost, 2);
            $totalTransferValue = bcadd($totalTransferValue, $lineTotal, 2);

            if ($item->item_type === 'ingredient' || ! empty($item->ingredient_id)) {
                $ingredientTransferValue = bcadd($ingredientTransferValue, $lineTotal, 2);
            } else {
                $retailTransferValue = bcadd($retailTransferValue, $lineTotal, 2);
            }
        }

        $payload = [
            'transfer_id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'from_store_id' => $transfer->from_store_id,
            'to_store_id' => $transfer->to_store_id,
            'accounting_date' => Carbon::parse($transfer->shipped_at ?? now())->toDateString(),
            'transfer_value' => $totalTransferValue,
            'retail_transfer_value' => $retailTransferValue,
            'ingredient_transfer_value' => $ingredientTransferValue,
        ];

        return $this->dispatcher->recordOutboxEvent(
            tenantId: (int) $transfer->tenant_id,
            storeId: (int) $transfer->from_store_id,
            eventType: 'inventory.transfer.shipped',
            sourceModule: 'INVENTORY',
            sourceType: 'STOCK_TRANSFER',
            sourceId: $transfer->id,
            sourceNumber: $transfer->transfer_number,
            idempotencyKey: "inventory:transfer:shipped:{$transfer->id}",
            payload: $payload,
            occurredAt: $transfer->shipped_at ? Carbon::parse($transfer->shipped_at) : now()
        );
    }

    /**
     * Emisi outbox saat Stock Transfer diterima di toko tujuan (inventory.transfer.received).
     * Akuntansi PR-INV-02:
     * Toko Tujuan: Dr Persediaan (Toko Tujuan) / Cr Persediaan Dalam Perjalanan (In-Transit)
     */
    public function recordStockTransferReceived(StockTransfer $transfer): FinancialEventOutbox
    {
        $items = $transfer->items()->get();
        $totalReceivedValue = '0.00';
        $retailReceivedValue = '0.00';
        $ingredientReceivedValue = '0.00';

        foreach ($items as $item) {
            $qty = (string) ($item->quantity_received ?? $item->quantity_transferred ?? $item->quantity ?? 0);
            $cost = (string) ($item->carrying_unit_cost ?? $item->unit_cost ?? 0);
            $lineTotal = bcmul($qty, $cost, 2);
            $totalReceivedValue = bcadd($totalReceivedValue, $lineTotal, 2);

            if ($item->item_type === 'ingredient' || ! empty($item->ingredient_id)) {
                $ingredientReceivedValue = bcadd($ingredientReceivedValue, $lineTotal, 2);
            } else {
                $retailReceivedValue = bcadd($retailReceivedValue, $lineTotal, 2);
            }
        }

        $payload = [
            'transfer_id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'from_store_id' => $transfer->from_store_id,
            'to_store_id' => $transfer->to_store_id,
            'accounting_date' => Carbon::parse($transfer->received_at ?? now())->toDateString(),
            'received_value' => $totalReceivedValue,
            'retail_received_value' => $retailReceivedValue,
            'ingredient_received_value' => $ingredientReceivedValue,
        ];

        return $this->dispatcher->recordOutboxEvent(
            tenantId: (int) $transfer->tenant_id,
            storeId: (int) $transfer->to_store_id,
            eventType: 'inventory.transfer.received',
            sourceModule: 'INVENTORY',
            sourceType: 'STOCK_TRANSFER',
            sourceId: $transfer->id,
            sourceNumber: $transfer->transfer_number,
            idempotencyKey: "inventory:transfer:received:{$transfer->id}",
            payload: $payload,
            occurredAt: $transfer->received_at ? Carbon::parse($transfer->received_at) : now()
        );
    }
}
