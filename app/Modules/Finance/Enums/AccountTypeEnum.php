<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum AccountTypeEnum: string
{
    case ASSET = 'ASSET';
    case LIABILITY = 'LIABILITY';
    case EQUITY = 'EQUITY';
    case REVENUE = 'REVENUE';
    case COGS = 'COGS';
    case EXPENSE = 'EXPENSE';
    case OTHER_INCOME = 'OTHER_INCOME';
    case OTHER_EXPENSE = 'OTHER_EXPENSE';

    public function defaultNormalBalance(): NormalBalanceEnum
    {
        return match ($this) {
            self::ASSET, self::COGS, self::EXPENSE, self::OTHER_EXPENSE => NormalBalanceEnum::DEBIT,
            self::LIABILITY, self::EQUITY, self::REVENUE, self::OTHER_INCOME => NormalBalanceEnum::CREDIT,
        };
    }

    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::ASSET, self::LIABILITY, self::EQUITY], true);
    }

    public function isIncomeStatement(): bool
    {
        return in_array($this, [self::REVENUE, self::COGS, self::EXPENSE, self::OTHER_INCOME, self::OTHER_EXPENSE], true);
    }
}
