<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum FixedAssetDisposalTypeEnum: string
{
    case SALE = 'SALE';
    case SCRAP = 'SCRAP';
    case WRITE_OFF = 'WRITE_OFF';
}
