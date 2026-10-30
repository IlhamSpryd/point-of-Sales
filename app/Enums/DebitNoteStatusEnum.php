<?php

declare(strict_types=1);

namespace App\Enums;

enum DebitNoteStatusEnum: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyApplied = 'partially_applied';
    case FullyApplied = 'fully_applied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyApplied => 'Partially Applied',
            self::FullyApplied => 'Fully Applied',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Issued => 'info',
            self::PartiallyApplied => 'primary',
            self::FullyApplied => 'success',
            self::Cancelled => 'secondary',
        };
    }

    public function canApply(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyApplied], true);
    }

    public function canCancel(): bool
    {
        return $this === self::Draft || $this === self::Issued;
    }
}
