<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum SalaryComponentTypeEnum: string
{
    case EARNING = 'EARNING';
    case DEDUCTION = 'DEDUCTION';
    case EMPLOYER_CONTRIBUTION = 'EMPLOYER_CONTRIBUTION';

    public function label(): string
    {
        return match ($this) {
            self::EARNING => 'Pendapatan Kotor (Earnings)',
            self::DEDUCTION => 'Potongan Upah Karyawan (Deductions)',
            self::EMPLOYER_CONTRIBUTION => 'Kontribusi Pemberi Kerja (Employer Contributions)',
        };
    }
}
