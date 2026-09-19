<?php

namespace App\Services;

use App\Exports\UsersExport;
use App\Models\User;
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
        $query = User::query()->with('role')->latest();
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
        return DB::transaction(function () use ($data) {
            return User::create($data);
        });
    }

    /**
     * Mengatur ulang rincian spesifik dari Pengguna.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            if (empty($data['password'])) {
                unset($data['password']);
            }
            if (empty($data['pin_code'])) {
                unset($data['pin_code']);
            }

            $user->update($data);

            return $user;
        });
    }

    /**
     * Coret pengguna. Pengguna ditolak menghapus profilnya sendiri!
     */
    public function delete(User $user): bool
    {
        if ($user->id === Auth::id()) {
            throw new Exception('Anda tidak dapat menghapus akun sendiri.');
        }

        if ($user->orders()->exists()) {
            throw new Exception('Data ini masih terhubung dengan data lain dan tidak dapat dihapus.');
        }

        return DB::transaction(function () use ($user) {
            return $user->delete();
        });
    }
}
