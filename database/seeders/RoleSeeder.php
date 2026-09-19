<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RoleSeeder: Mengisi 6 role standar yang dipersyaratkan.
 * 1. Owner
 * 2. Manager
 * 3. Kasir
 * 4. Waiter
 * 5. Barista
 * 6. Inventory
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Owner'],
            ['name' => 'Manager'],
            ['name' => 'Kasir'],
            ['name' => 'Waiter'],
            ['name' => 'Barista'],
            ['name' => 'Inventory'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']]);
        }
    }
}
