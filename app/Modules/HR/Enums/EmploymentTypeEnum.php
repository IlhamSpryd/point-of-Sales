<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmploymentTypeEnum: string
{
    case PERMANENT = 'PERMANENT';
    case CONTRACT_PKWT = 'CONTRACT_PKWT';
    case PROBATION = 'PROBATION';
    case INTERN = 'INTERN';
    case DAILY_WORKER = 'DAILY_WORKER';

    public function label(): string
    {
        return match ($this) {
            self::PERMANENT => 'Permanent (PKWTT)',
            self::CONTRACT_PKWT => 'Contract (PKWT)',
            self::PROBATION => 'Probation',
            self::INTERN => 'Internship',
            self::DAILY_WORKER => 'Daily Worker',
        };
    }
}
