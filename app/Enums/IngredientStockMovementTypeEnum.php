<?php

declare(strict_types=1);

namespace App\Enums;

enum IngredientStockMovementTypeEnum: string
{
    // Legacy types
    case SaleDeduction = 'sale_deduction';
    case PurchaseReceipt = 'purchase_receipt';
    case Waste = 'waste';
    case Adjustment = 'adjustment';
    case RestoreCompensation = 'restore_compensation';
    case InitialSync = 'initial_sync';

    // Module 4 Inventory & Warehouse Management types
    case GoodsReceipt = 'goods_receipt';
    case GoodsReceiptReversal = 'goods_receipt_reversal';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case TransferReturnIn = 'transfer_return_in';
    case TransferShrinkage = 'transfer_shrinkage';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case OpnameAdjustment = 'opname_adjustment';
    case SupplierReturnOut = 'supplier_return_out';

    public function isPositive(): bool
    {
        return in_array($this, [
            self::PurchaseReceipt,
            self::RestoreCompensation,
            self::InitialSync,
            self::GoodsReceipt,
            self::TransferIn,
            self::TransferReturnIn,
            self::AdjustmentIn,
        ], true);
    }

    public function isNegative(): bool
    {
        return in_array($this, [
            self::SaleDeduction,
            self::Waste,
            self::GoodsReceiptReversal,
            self::TransferOut,
            self::TransferShrinkage,
            self::AdjustmentOut,
            self::SupplierReturnOut,
        ], true);
    }
}
