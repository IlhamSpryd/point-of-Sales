<?php

declare(strict_types=1);

// [OMEGA-NODE1] Emergency Backend Hardening | 2026-09-21

namespace App\Enums;

enum StockMovementType: string
{
    case RestoreCompensation = 'restore_compensation';
}
