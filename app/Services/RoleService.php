<?php

namespace App\Services;

use App\Exports\RolesExport;
use App\Models\Role;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * RoleService bertindak memisahkan keseluruhan kerumitan logika sistem
 * terkait pemrosesan data Role murni (Ekspor, Penjaringan/Filter).
 */
class RoleService
{
    /**
     * Menyusun cetak biru kueri Eloquent dengan menempelkan agregat (withCount)
     * dan injeksi klausul pencarian jika requested.
     */
    public function getFilteredQuery(Request $request): Builder
    {
        $query = Role::query()->withCount('users')->latest('id');
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        return $query;
    }

    /**
     * Menyiapkan file unduhan mentah .CSV.
     */
    public function exportCsv(Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'roles_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new RolesExport($query), $fileName);
    }

    /**
     * Mengamankan proses simpan data Peran baru.
     */
    public function store(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $data['permissions'] = $data['permissions'] ?? null;

            return Role::create($data);
        });
    }

    /**
     * Modifikasi rincian satu spesifik entitas Peran yang sudah ada.
     */
    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $data['permissions'] = $data['permissions'] ?? null;
            $role->update($data);

            return $role;
        });
    }

    /**
     * Menghapus jabatan/peran. Ditolak jika masih ada akun staf yang menempati jabatan ini.
     */
    public function delete(Role $role): bool
    {
        return DB::transaction(function () use ($role) {
            if ($role->users()->count() > 0) {
                throw new Exception('Tidak dapat menghapus role "'.$role->name.'" karena '.$role->users()->count().' pengguna masih menggunakan role ini.');
            }

            return $role->delete();
        });
    }
}
