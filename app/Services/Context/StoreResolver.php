<?php

namespace App\Services\Context;

use App\Models\Store;
use App\Models\User;

/**
 * Resolusi store (cabang) aktif.
 *
 * Aturan (H-03):
 *  - Owner  : semua store milik tenant.
 *  - lainnya: hanya store pada baris user_stores milik user.
 *  - Bila himpunan kosong dan tenant hanya punya SATU store -> store itu.
 *  - Selain itu -> 403 (store aktif tidak bisa ditentukan).
 *  - Store aktif = nilai session bila termasuk himpunan, jika tidak store pertama.
 *  - TIDAK PERNAH membaca store dari input request.
 */
class StoreResolver
{
    /**
     * @return array<int, int> himpunan store yang diizinkan (terurut)
     */
    public function allowedStoreIdsFor(User $user, int $tenantId): array
    {
        $roleName = $user->role?->name;
        $isOwner = $roleName === 'Owner' || (bool) ($user->role->is_admin ?? false);

        if ($isOwner) {
            return Store::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $assigned = $user->stores()
            ->where('stores.tenant_id', $tenantId)
            ->orderBy('stores.id')
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! empty($assigned)) {
            return $assigned;
        }

        // Fallback: tenant hanya punya satu store -> store tersebut.
        $all = Store::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return count($all) === 1 ? $all : [];
    }

    /**
     * @param  array<int, int>  $allowed
     */
    public function resolve(array $allowed, ?int $sessionStoreId): int
    {
        if (empty($allowed)) {
            abort(403, 'Tidak ada cabang (store) yang dapat diakses untuk pengguna ini.');
        }

        if ($sessionStoreId !== null && in_array($sessionStoreId, $allowed, true)) {
            return $sessionStoreId;
        }

        return $allowed[0];
    }
}
