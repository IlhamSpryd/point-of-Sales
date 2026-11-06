<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseCostCategoryEnum: string
{
    case Freight = 'FREIGHT';
    case Insurance = 'INSURANCE';
    case CustomsDuty = 'CUSTOMS_DUTY';
    case Handling = 'HANDLING';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Freight => 'Freight & Shipping',
            self::Insurance => 'Shipping Insurance',
            self::CustomsDuty => 'Customs & Duties',
            self::Handling => 'Handling & Logistics',
            self::Other => 'Other Procurement Costs',
        };
    }
}
