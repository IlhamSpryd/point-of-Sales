<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
}
