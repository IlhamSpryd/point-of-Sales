<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum CreditNoteStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case PARTIALLY_APPLIED = 'PARTIALLY_APPLIED';
    case APPLIED = 'APPLIED';
    case CONVERTED_TO_STORE_CREDIT = 'CONVERTED_TO_STORE_CREDIT';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ISSUED => 'Issued',
            self::PARTIALLY_APPLIED => 'Partially Applied',
            self::APPLIED => 'Fully Applied',
            self::CONVERTED_TO_STORE_CREDIT => 'Converted to Store Credit',
            self::VOIDED => 'Voided',
        };
    }
}
