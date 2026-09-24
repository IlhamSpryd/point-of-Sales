<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $roles = [
            ['id' => 1, 'role_code' => 'ROLE-OWN', 'name' => 'Owner',      'description' => 'Pemilik bisnis — akses penuh', 'permissions' => json_encode(['*']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'role_code' => 'ROLE-MGR', 'name' => 'Manager',    'description' => 'Manajer operasional', 'permissions' => json_encode(['dashboard', 'reports', 'orders.*', 'users.view', 'shifts.*', 'inventory.*', 'discounts.*']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'role_code' => 'ROLE-KSR', 'name' => 'Kasir',      'description' => 'Kasir point of sale', 'permissions' => json_encode(['orders.create', 'orders.view', 'payments.create', 'shifts.own']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'role_code' => 'ROLE-WTR', 'name' => 'Waiter',     'description' => 'Pelayan restoran', 'permissions' => json_encode(['orders.create', 'orders.view', 'tables.view']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'role_code' => 'ROLE-BRS', 'name' => 'Barista',    'description' => 'Barista pembuat minuman', 'permissions' => json_encode(['orders.view', 'kds.view', 'kds.update']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'role_code' => 'ROLE-INV', 'name' => 'Inventory',  'description' => 'Staff inventaris gudang', 'permissions' => json_encode(['inventory.*', 'ingredients.*']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'role_code' => 'ROLE-SPV', 'name' => 'Supervisor', 'description' => 'Supervisor shift', 'permissions' => json_encode(['orders.*', 'shifts.*', 'reports.daily', 'void.approve']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8, 'role_code' => 'ROLE-KCK', 'name' => 'Cook',       'description' => 'Koki dapur', 'permissions' => json_encode(['orders.view', 'kds.view', 'kds.update']), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                $role
            );
        }
    }
}
