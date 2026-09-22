<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethodEnum: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Ewallet = 'ewallet';
    case Card = 'card';
}
