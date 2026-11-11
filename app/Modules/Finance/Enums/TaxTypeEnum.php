<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum TaxTypeEnum: string
{
    case VAT_PPN = 'VAT_PPN';
    case LOCAL_PB1 = 'LOCAL_PB1';
    case WITHHOLDING_PPH = 'WITHHOLDING_PPH';
    case EXEMPT = 'EXEMPT';
    case SST_SALES = 'SST_SALES';
    case SST_SERVICE = 'SST_SERVICE';
    case GST_SG = 'GST_SG';
    case VAT_PH = 'VAT_PH';
}
