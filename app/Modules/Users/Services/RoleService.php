<?php

namespace App\Modules\Users\Services;

use App\Exports\RolesExport;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * RoleService bertindak memisahkan keseluruhan kerumitan logika sistem
 * terkait pemrosesan data Role murni (Ekspor, Penjaringan/Filter).
 */
class RoleService
{
    /**
     * @return Builder<Role>
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
    public function exportCsv(Request $request): BinaryFileResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'roles_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new RolesExport($query), $fileName);
    }

    /**
     * Normalisasi struktur array permissions agar kompatibel dengan User::hasPermission()
     * baik dari input array sekuensial ['orders.view', ...] maupun format asosiatif legacy ['can_access_pos' => '1'].
     *
     * @return list<string>
     */
    public function normalizePermissions(mixed $permissions): array
    {
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            $permissions = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($permissions) || $permissions === []) {
            return [];
        }

        $legacyMap = [
            'can_access_pos' => 'orders.create',
            'can_void_order' => 'orders.void',
            'can_give_discount' => 'pos.discount.manual_apply',
            'can_access_kds' => 'kds.access',
            'can_manage_menu' => 'products.manage',
            'can_view_reports' => 'reports.sales.view',
        ];

        $normalized = [];

        foreach ($permissions as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && trim($value) !== '') {
                    $perm = trim($value);
                    $normalized[] = $perm;
                    if (isset($legacyMap[$perm])) {
                        $normalized[] = $legacyMap[$perm];
                    }
                }
            } elseif (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                $perm = trim($key);
                if ($perm !== '') {
                    $normalized[] = $perm;
                    if (isset($legacyMap[$perm])) {
                        $normalized[] = $legacyMap[$perm];
                    }
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Mengamankan proses simpan data Peran baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $data['permissions'] = $this->normalizePermissions($data['permissions'] ?? null);

            return Role::create($data);
        });
    }

    /**
     * Modifikasi rincian satu spesifik entitas Peran yang sudah ada.
     * Menerapkan Immutable Owner Role Guard untuk mencegah self-lockout organisasi.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            if ($role->name === 'Owner') {
                $data['name'] = 'Owner';
                $data['is_active'] = true;
                $data['permissions'] = ['*'];
            } else {
                $data['permissions'] = $this->normalizePermissions($data['permissions'] ?? null);
            }

            $role->update($data);

            return $role;
        });
    }

    /**
     * Menghapus jabatan/peran. Ditolak jika peran adalah Owner atau masih ada akun staf TENANT INI yang menempati jabatan ini.
     */
    public function delete(Role $role): bool
    {
        return DB::transaction(function () use ($role) {
            if ($role->name === 'Owner') {
                throw new Exception('Cannot delete the system Owner role (Self-Lockout Protection).');
            }

            $usersCount = $role->users()->where('users.tenant_id', app(TenantContext::class)->requireTenantId())->count();
            if ($usersCount > 0) {
                throw new Exception('Cannot delete role "'.$role->name.'" because '.$usersCount.' user(s) are assigned to it.');
            }

            return (bool) $role->delete();
        });
    }

    /**
     * Provisioning: salin role standar Pos\RoleSeeder (nama + permissions) ke tenant tertentu.
     * Dipanggil saat tenant baru dibuat atau via artisan tenant:provision-roles {tenant}.
     */
    public function provisionDefaultRoles(Tenant $tenant): void
    {
        $defaults = [
            ['name' => 'Owner', 'description' => 'Pemilik bisnis — akses penuh', 'permissions' => ['*']],
            ['name' => 'Admin', 'description' => 'Administrator organisasi & tata kelola', 'permissions' => ['dashboard', 'reports.*', 'orders.*', 'users.*', 'roles.*', 'stores.*', 'settings.*', 'shifts.*', 'inventory.*', 'purchasing.*', 'customers.*', 'products.*', 'pricing.*', 'finance.*', 'hr.*']],
            ['name' => 'Manager', 'description' => 'Manajer operasional cabang', 'permissions' => ['dashboard', 'reports', 'reports.*', 'orders.*', 'users.view', 'shifts.*', 'inventory.*', 'purchasing.*', 'customers.*', 'products.*', 'pricing.*', 'discounts.*', 'finance.*', 'hr.*']],
            ['name' => 'Finance', 'description' => 'Staf keuangan & akuntansi', 'permissions' => ['finance.*', 'sales.orders.*', 'sales.invoices.*', 'purchasing.invoice.*', 'customers.view', 'customers.credit.*', 'reports.financial.*']],
            ['name' => 'Sales', 'description' => 'Staf penjualan B2B & akun pelanggan', 'permissions' => ['sales.*', 'customers.*', 'products.view', 'pricing.view']],
            ['name' => 'Kasir', 'description' => 'Kasir point of sale', 'permissions' => ['orders.create', 'orders.view', 'payments.create', 'shifts.own']],
            ['name' => 'Waiter', 'description' => 'Pelayan restoran', 'permissions' => ['orders.create', 'orders.view', 'tables.view']],
            ['name' => 'Barista', 'description' => 'Barista pembuat minuman', 'permissions' => ['orders.view', 'kds.view', 'kds.update', 'kds.access']],
            ['name' => 'Inventory', 'description' => 'Staff inventaris gudang & pengadaan', 'permissions' => ['inventory.*', 'ingredients.*', 'purchasing.*', 'products.view', 'pricing.view']],
            ['name' => 'Supervisor', 'description' => 'Supervisor shift operasional', 'permissions' => ['orders.*', 'shifts.*', 'reports.daily', 'void.approve', 'pos.discount.override_approve']],
            ['name' => 'Cook', 'description' => 'Koki dapur', 'permissions' => ['orders.view', 'kds.view', 'kds.update', 'kds.access']],
        ];

        app(TenantContext::class)->runAs($tenant->id, null, function () use ($defaults, $tenant) {
            foreach ($defaults as $default) {
                $roleCode = match ($default['name']) {
                    'Owner' => 'ROL-OWNER',
                    'Admin' => 'ROL-ADMIN',
                    'Manager' => 'ROL-MANAGER',
                    'Finance' => 'ROL-FINANCE',
                    'Sales' => 'ROL-SALES',
                    'Kasir' => 'ROL-KASIR',
                    'Waiter' => 'ROL-WAITER',
                    'Barista' => 'ROL-BARISTA',
                    'Inventory' => 'ROL-INVENTORY',
                    'Supervisor' => 'ROL-SUPERVISOR',
                    'Cook' => 'ROL-COOK',
                    default => 'ROL-'.strtoupper(substr($default['name'], 0, 6)),
                };

                Role::firstOrCreate(
                    ['name' => $default['name'], 'tenant_id' => $tenant->id],
                    [
                        'role_code' => $roleCode,
                        'description' => $default['description'],
                        'permissions' => $default['permissions'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
