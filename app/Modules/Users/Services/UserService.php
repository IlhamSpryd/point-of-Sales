<?php

namespace App\Modules\Users\Services;

use App\Exports\UsersExport;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * UserService adalah otak pemrosesan di balik layar yang berkaitan erat
 * dengan akun staf pengguna (Penyaringan dan Keamanan).
 */
class UserService
{
    /**
     * Meracik Kueri tabel Users. Memuat relasi peran (Eager Loading target 'role')
     * serta filter grup pencarian Nama dan Email.
     */
    public function getFilteredQuery(Request $request): Builder
    {
        // User TIDAK punya global scope tenant (BD-7: login menemukan user hanya via email),
        // jadi scoping dilakukan secara eksplisit di sini.
        $query = User::query()
            ->where('tenant_id', app(TenantContext::class)->requireTenantId())
            ->with('role')->latest('id');
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Mencetak daftar Akun secara format Excel.
     */
    public function exportCsv(Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'users_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new UsersExport($query), $fileName);
    }

    /**
     * Mendaftarkan Pengguna baru.
     */
    public function store(array $data): User
    {
        if (isset($data['password'])) {
            $data['password_hash'] = $data['password'];
            unset($data['password']);
        }
        if (isset($data['pin_code'])) {
            $data['pin_hash'] = $data['pin_code'];
            unset($data['pin_code']);
        }

        return DB::transaction(function () use ($data) {
            $user = new User($data);
            $user->tenant_id = app(TenantContext::class)->requireTenantId();
            $user->save();

            return $user;
        });
    }

    /**
     * Mengatur ulang rincian spesifik dari Pengguna.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // Cegah penonaktifan atau penangguhan kasir jika masih memiliki shift aktif
            $isBeingDeactivated = (isset($data['is_active']) && ! $data['is_active'])
                || (isset($data['status']) && in_array($data['status'], ['suspended', 'inactive'], true));

            if ($isBeingDeactivated) {
                $hasOpenShift = Shift::where('user_id', $user->id)
                    ->where('status', 'open')
                    ->exists();

                if ($hasOpenShift) {
                    throw new \DomainException('Cannot deactivate a cashier with an open active shift. Please close the active shift first.');
                }
            }

            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $data['password_hash'] = $data['password'];
                unset($data['password']);
            }

            if (empty($data['pin_code'])) {
                unset($data['pin_code']);
            } else {
                $data['pin_hash'] = $data['pin_code'];
                unset($data['pin_code']);
            }

            $user->update($data);

            // Invalidate active sessions if credentials or roles change
            if (isset($data['password_hash']) || (isset($data['is_active']) && ! $data['is_active']) || isset($data['role_id']) || isset($data['status'])) {
                try {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                } catch (\Throwable) {
                }
            }

            return $user;
        });
    }

    /**
     * Delete user. Users cannot delete their own account.
     */
    public function delete(User $user): bool
    {
        if ($user->id === Auth::id()) {
            throw new Exception('You cannot delete your own account.');
        }

        $hasOpenShift = Shift::where('user_id', $user->id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            throw new \DomainException('Cannot delete a cashier with an open active shift. Please close the active shift first.');
        }

        if ($user->orders()->exists()) {
            throw new Exception('This user has associated transactions or orders and cannot be deleted.');
        }

        return DB::transaction(function () use ($user) {
            try {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } catch (\Throwable) {
            }

            return $user->delete();
        });
    }

    /**
     * Revoke store access for a user.
     * Rejected if user has an open active shift at that store.
     */
    public function revokeStoreAccess(User $user, int $storeId): void
    {
        $hasOpenShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            throw new \DomainException('Cannot revoke store branch access for a user with an open active shift at this branch. Please close the active shift first.');
        }

        DB::table('user_stores')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->delete();
    }
}
