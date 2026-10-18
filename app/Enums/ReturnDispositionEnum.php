<?php

declare(strict_types=1);

namespace App\Enums;

enum ReturnDispositionEnum: string
{
    case Restock = 'restock';
    case Quarantine = 'quarantine';
    case WriteOffWaste = 'write_off_waste';

    public function isRestock(): bool
    {
        return $this === self::Restock;
    }

    public function isQuarantine(): bool
    {
        return $this === self::Quarantine;
    }

    public function isWriteOffWaste(): bool
    {
        return $this === self::WriteOffWaste;
    }
}
