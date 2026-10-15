<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

/**
 * [D2-BE-004] Policy Shift: buka/tutup shift hanya untuk peran kas dan
 * pemilik shift yang sama (kecuali Owner/Manager yang merekonsiliasi).
 */
class ShiftPolicy
{
    public function open(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true);
    }

    public function close(User $user, Shift $shift): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true)
            && ((int) $user->id === (int) $shift->user_id
                || in_array($user->role?->name, ['Owner', 'Manager'], true))
            && (int) $user->tenant_id === (int) $shift->tenant_id;
    }

    public function recordCashMovement(User $user, Shift $shift): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir'], true)
            && (int) $user->id === (int) $shift->user_id
            && (int) $user->tenant_id === (int) $shift->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Shift $shift): bool
    {
        return (int) $user->tenant_id === (int) $shift->tenant_id
            && ((int) $user->id === (int) $shift->user_id
                || in_array($user->role?->name, ['Owner', 'Manager'], true));
    }
}
