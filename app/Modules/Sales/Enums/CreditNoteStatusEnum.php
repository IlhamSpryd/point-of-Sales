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
            self::DRAFT => 'Draf',
            self::ISSUED => 'Diterbitkan',
            self::PARTIALLY_APPLIED => 'Diterapkan Sebagian',
            self::APPLIED => 'Diterapkan Penuh',
            self::CONVERTED_TO_STORE_CREDIT => 'Dikonversi ke Store Credit',
            self::VOIDED => 'Dibatalkan',
        };
    }
}
