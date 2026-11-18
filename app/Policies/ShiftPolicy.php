<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Shift;

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
                || (int) $user->id === (int) ($shift->active_cashier_id ?? $shift->user_id)
                || in_array($user->role->name, ['Owner', 'Manager', 'Supervisor'], true))
            && (int) $user->tenant_id === (int) $shift->tenant_id;
    }

    public function forceClose(User $user, Shift $shift): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Supervisor'], true)
            && (int) $user->tenant_id === (int) $shift->tenant_id;
    }

    public function handover(User $user, Shift $shift): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true)
            && ((int) $user->id === (int) ($shift->active_cashier_id ?? $shift->user_id)
                || in_array($user->role->name, ['Owner', 'Manager', 'Supervisor'], true))
            && (int) $user->tenant_id === (int) $shift->tenant_id;
    }

    public function recordCashMovement(User $user, Shift $shift): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true)
            && ((int) $user->id === (int) ($shift->active_cashier_id ?? $shift->user_id)
                || in_array($user->role->name, ['Owner', 'Manager', 'Supervisor'], true))
            && (int) $user->tenant_id === (int) $shift->tenant_id;
    }

    /**
     * R-009 / FINDING-NAV-01: hanya Owner/Manager/Supervisor/Admin yang boleh
     * melihat riwayat shift seluruh kasir. Kasir biasa tetap bisa membuka
     * halaman untuk shift miliknya via route 'shifts.show', tapi tautan
     * indeks disembunyikan dari sub-nav.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Supervisor', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, Shift $shift): bool
    {
        return (int) $user->tenant_id === (int) $shift->tenant_id
            && ((int) $user->id === (int) $shift->user_id
                || in_array($user->role?->name, ['Owner', 'Manager'], true));
    }
}
