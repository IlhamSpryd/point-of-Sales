<?php

namespace App\Services;

use App\Exports\RolesExport;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
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
        // Role punya TenantScope global — query otomatis di-scope ke tenant konteks aktif.
        // Hanya hitung user milik tenant yang sama.
        $query = Role::query()->withCount([
            'users' => fn ($q) => $q->where('users.tenant_id', app(TenantContext::class)->requireTenantId()),
        ])->latest('id');
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
     * Menghapus jabatan/peran. Ditolak jika masih ada akun staf TENANT INI yang menempati jabatan ini.
     */
    public function delete(Role $role): bool
    {
        return DB::transaction(function () use ($role) {
            $usersCount = $role->users()->where('users.tenant_id', app(TenantContext::class)->requireTenantId())->count();
            if ($usersCount > 0) {
                throw new Exception('Tidak dapat menghapus role "'.$role->name." karena {$usersCount} pengguna masih menggunakan role ini.");
            }

            return $role->delete();
        });
    }

    /**
     * Provisioning: salin 8 role standar Pos\RoleSeeder (nama + permissions) ke tenant tertentu.
     * Dipanggil saat tenant baru dibuat atau via artisan tenant:provision-roles {tenant}.
     */
    public function provisionDefaultRoles(Tenant $tenant): void
    {
        $defaults = [
            ['name' => 'Owner', 'description' => 'Pemilik bisnis — akses penuh', 'permissions' => ['*']],
            ['name' => 'Manager', 'description' => 'Manajer operasional', 'permissions' => ['dashboard', 'reports', 'orders.*', 'users.view', 'shifts.*', 'inventory.*', 'discounts.*']],
            ['name' => 'Kasir', 'description' => 'Kasir point of sale', 'permissions' => ['orders.create', 'orders.view', 'payments.create', 'shifts.own']],
            ['name' => 'Waiter', 'description' => 'Pelayan restoran', 'permissions' => ['orders.create', 'orders.view', 'tables.view']],
            ['name' => 'Barista', 'description' => 'Barista pembuat minuman', 'permissions' => ['orders.view', 'kds.view', 'kds.update']],
            ['name' => 'Inventory', 'description' => 'Staff inventaris gudang', 'permissions' => ['inventory.*', 'ingredients.*']],
            ['name' => 'Supervisor', 'description' => 'Supervisor shift', 'permissions' => ['orders.*', 'shifts.*', 'reports.daily', 'void.approve']],
            ['name' => 'Cook', 'description' => 'Koki dapur', 'permissions' => ['orders.view', 'kds.view', 'kds.update']],
        ];

        app(TenantContext::class)->runAs($tenant->id, null, function () use ($defaults, $tenant) {
            foreach ($defaults as $default) {
                Role::firstOrCreate(
                    ['name' => $default['name'], 'tenant_id' => $tenant->id],
                    [
                        'description' => $default['description'],
                        'permissions' => $default['permissions'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
