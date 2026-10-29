<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum FixedAssetStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case FULLY_DEPRECIATED = 'FULLY_DEPRECIATED';
    case DISPOSED = 'DISPOSED';
    case IMPAIRED = 'IMPAIRED';

    public function isDepreciable(): bool
    {
        return $this === self::ACTIVE;
    }
}
