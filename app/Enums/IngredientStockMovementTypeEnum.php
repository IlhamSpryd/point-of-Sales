<?php

declare(strict_types=1);

namespace App\Enums;

enum IngredientStockMovementTypeEnum: string
{
    case SaleDeduction = 'sale_deduction';
    case PurchaseReceipt = 'purchase_receipt';
    case Waste = 'waste';
    case Adjustment = 'adjustment';
    case RestoreCompensation = 'restore_compensation';
}
