<?php

declare(strict_types=1);

namespace App\Enums;

enum ReorderSuggestionStatusEnum: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka (Open)',
            self::Converted => 'Dikonversi ke PR (Converted)',
            self::Dismissed => 'Diabaikan (Dismissed)',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Converted => 'success',
            self::Dismissed => 'secondary',
        };
    }
}
