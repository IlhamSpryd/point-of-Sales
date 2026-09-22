<?php

declare(strict_types=1);

namespace App\Enums;

enum CashDrawerMovementCategoryEnum: string
{
    case RestockChange = 'restock_change';
    case PettyExpense = 'petty_expense';
    case OwnerWithdrawal = 'owner_withdrawal';
    case BankDeposit = 'bank_deposit';
    case Correction = 'correction';
}
