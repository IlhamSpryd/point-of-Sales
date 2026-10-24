<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseCostCategoryEnum: string
{
    case Freight = 'FREIGHT';
    case Insurance = 'INSURANCE';
    case CustomsDuty = 'CUSTOMS_DUTY';
    case Handling = 'HANDLING';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Freight => 'Ongkos Kirim (Freight)',
            self::Insurance => 'Asuransi Pengiriman (Insurance)',
            self::CustomsDuty => 'Bea Masuk & Cukai (Customs Duty)',
            self::Handling => 'Bongkar Muat & Penanganan (Handling)',
            self::Other => 'Biaya Pengadaan Lainnya (Other)',
        };
    }
}
