<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Roles terlebih dahulu (Administrator, Kasir, Pimpinan)
        $this->call(RoleSeeder::class);

        // 2. Buat akun default Administrator
        $adminRole = \App\Models\Role::where('name', 'Administrator')->first();

        User::updateOrCreate(
            ['email' => 'admin@pos.test'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('12345678'),
                'role_id' => $adminRole->id,
            ]
        );

        // 3. Buat akun Kasir untuk demo
        $kasirRole = \App\Models\Role::where('name', 'Kasir')->first();

        User::updateOrCreate(
            ['email' => 'kasir@pos.test'],
            [
                'name' => 'Kasir Demo',
                'password' => Hash::make('12345678'),
                'role_id' => $kasirRole->id,
            ]
        );

        // 4. Buat akun Pimpinan untuk demo
        $pimpinanRole = \App\Models\Role::where('name', 'Pimpinan')->first();

        User::updateOrCreate(
            ['email' => 'pimpinan@pos.test'],
            [
                'name' => 'Pimpinan Demo',
                'password' => Hash::make('12345678'),
                'role_id' => $pimpinanRole->id,
            ]
        );
    }
}
