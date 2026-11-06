<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum AttendanceSourceEnum: string
{
    case POS_KIOSK = 'POS_KIOSK';
    case BRANCH_TABLET = 'BRANCH_TABLET';
    case WEB_PORTAL = 'WEB_PORTAL';
    case MANUAL_CORRECTION = 'MANUAL_CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::POS_KIOSK => 'POS Kiosk Terminal',
            self::BRANCH_TABLET => 'Branch Tablet (QR Code)',
            self::WEB_PORTAL => 'Web Self-Service Portal',
            self::MANUAL_CORRECTION => 'Manual Correction',
        };
    }
}
