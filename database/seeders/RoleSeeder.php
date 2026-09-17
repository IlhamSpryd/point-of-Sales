<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RoleSeeder: Mengisi 3 role standar yang dipersyaratkan oleh ERD.
 * 1. Administrator — Kelola master data (Produk, Kategori, User)
 * 2. Kasir — Melakukan transaksi penjualan
 * 3. Pimpinan — Melihat laporan penjualan
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Administrator'],
            ['name' => 'Kasir'],
            ['name' => 'Pimpinan'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']]);
        }
    }
}
