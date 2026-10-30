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
            self::POS_KIOSK => 'Terminal POS Kasir',
            self::BRANCH_TABLET => 'Tablet Cabang (QR Code)',
            self::WEB_PORTAL => 'Portal Mandiri Web',
            self::MANUAL_CORRECTION => 'Koreksi Manual',
        };
    }
}
