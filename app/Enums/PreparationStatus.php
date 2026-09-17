<?php

namespace App\Enums;

enum PreparationStatus: string
{
    case Pending = 'pending';
    case Brewing = 'brewing';
    case Ready = 'ready';
}
