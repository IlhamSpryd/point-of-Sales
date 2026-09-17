<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        $adminRole = Role::where('name', 'Administrator')->first();

        User::updateOrCreate(
            ['email' => 'admin@pos.test'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('12345678'),
                'role_id' => $adminRole->id,
            ]
        );

        // 3. Buat akun Kasir untuk demo
        $kasirRole = Role::where('name', 'Kasir')->first();

        User::updateOrCreate(
            ['email' => 'kasir@pos.test'],
            [
                'name' => 'Kasir Demo',
                'password' => Hash::make('12345678'),
                'role_id' => $kasirRole->id,
            ]
        );

        // 4. Buat akun Pimpinan untuk demo
        $pimpinanRole = Role::where('name', 'Pimpinan')->first();

        User::updateOrCreate(
            ['email' => 'pimpinan@pos.test'],
            [
                'name' => 'Pimpinan Demo',
                'password' => Hash::make('12345678'),
                'role_id' => $pimpinanRole->id,
            ]
        );

        // 5. Akun sistem khusus atribusi transaksi self-order pelanggan (BUKAN untuk login manual).
        // Dipakai sebagai "kasir virtual" agar user_id di tabel orders tidak pernah null,
        // menjaga konsistensi audit trail sesuai aturan proyek ini.
        User::updateOrCreate(
            ['email' => config('pos.self_order_system_email', 'selforder@system.local')],
            [
                'name' => 'Self-Order Kiosk',
                'password' => Hash::make(Str::random(40)), // password acak, tidak pernah dipakai login
                'role_id' => $kasirRole->id,
            ]
        );

        // 6. Seed data Modifiers untuk self-order
        $this->call(ModifierSeeder::class);
    }
}
