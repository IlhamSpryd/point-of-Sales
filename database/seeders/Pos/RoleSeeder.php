<?php

namespace Database\Seeders\Pos;

use Database\Seeders\Pos\Concerns\StampsTenantOnBulkInsert;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    use StampsTenantOnBulkInsert;

    public function run(): void
    {
        $now = now();

        $roles = [
            ['id' => 1, 'role_code' => 'ROLE-OWN', 'name' => 'Owner',      'description' => 'Pemilik bisnis — akses penuh', 'permissions' => json_encode(['*']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'role_code' => 'ROLE-MGR', 'name' => 'Manager',    'description' => 'Manajer operasional', 'permissions' => json_encode(['dashboard', 'reports', 'reports.*', 'orders.*', 'users.view', 'shifts.*', 'inventory.*', 'purchasing.*', 'customers.*', 'products.*', 'pricing.*', 'discounts.*', 'finance.*', 'hr.*', 'stores.switch']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'role_code' => 'ROLE-KSR', 'name' => 'Kasir',      'description' => 'Kasir point of sale', 'permissions' => json_encode(['orders.create', 'orders.view', 'payments.create', 'shifts.own']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'role_code' => 'ROLE-WTR', 'name' => 'Waiter',     'description' => 'Pelayan restoran', 'permissions' => json_encode(['orders.create', 'orders.view', 'tables.view']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'role_code' => 'ROLE-BRS', 'name' => 'Barista',    'description' => 'Barista pembuat minuman', 'permissions' => json_encode(['orders.view', 'kds.view', 'kds.update', 'kds.access']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'role_code' => 'ROLE-INV', 'name' => 'Inventory',  'description' => 'Staff inventaris gudang', 'permissions' => json_encode(['inventory.*', 'ingredients.*', 'purchasing.*', 'products.view', 'pricing.view']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'role_code' => 'ROLE-SPV', 'name' => 'Supervisor', 'description' => 'Supervisor shift', 'permissions' => json_encode(['orders.*', 'shifts.*', 'reports.daily', 'void.approve', 'pos.discount.override_approve', 'stores.switch']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8, 'role_code' => 'ROLE-KCK', 'name' => 'Cook',       'description' => 'Koki dapur', 'permissions' => json_encode(['orders.view', 'kds.view', 'kds.update', 'kds.access']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 9, 'role_code' => 'ROLE-FIN', 'name' => 'Finance',    'description' => 'Staf keuangan & akuntansi', 'permissions' => json_encode(['finance.*', 'sales.orders.*', 'sales.invoices.*', 'purchasing.invoice.*', 'customers.view', 'customers.credit.*', 'reports.financial.*']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 10, 'role_code' => 'ROLE-SLS', 'name' => 'Sales',     'description' => 'Staf penjualan B2B & akun pelanggan', 'permissions' => json_encode(['sales.*', 'customers.*', 'products.view', 'pricing.view']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'role_code' => 'ROLE-ADM', 'name' => 'Admin',     'description' => 'Administrator organisasi & tata kelola', 'permissions' => json_encode(['dashboard', 'reports.*', 'orders.*', 'users.*', 'roles.*', 'stores.*', 'settings.*', 'shifts.*', 'inventory.*', 'purchasing.*', 'customers.*', 'products.*', 'pricing.*', 'finance.*', 'hr.*']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                $role
            );
        }
    }
}
