<?php

namespace Database\Seeders;

use App\Models\ModifierGroup;
use Illuminate\Database\Seeder;

class ModifierSeeder extends Seeder
{
    public function run(): void
    {
        // Grup 1: Pilihan Suhu (wajib diisi, hanya boleh pilih satu)
        $suhu = ModifierGroup::firstOrCreate(
            ['name' => 'Pilihan Suhu'],
            ['selection_type' => 'single', 'is_required' => true]
        );
        $suhu->modifiers()->createMany([
            ['name' => 'Ice', 'extra_price' => 0, 'is_default' => true],
            ['name' => 'Hot', 'extra_price' => 0, 'is_default' => false],
        ]);

        // Grup 2: Tingkat Gula (wajib diisi, hanya boleh pilih satu)
        $gula = ModifierGroup::firstOrCreate(
            ['name' => 'Tingkat Gula'],
            ['selection_type' => 'single', 'is_required' => true]
        );
        $gula->modifiers()->createMany([
            ['name' => 'Normal', 'extra_price' => 0, 'is_default' => true],
            ['name' => 'Less Sugar', 'extra_price' => 0, 'is_default' => false],
            ['name' => 'No Sugar', 'extra_price' => 0, 'is_default' => false],
        ]);

        // Grup 3: Jenis Susu (opsional, boleh pilih lebih dari satu jenis campuran)
        $susu = ModifierGroup::firstOrCreate(
            ['name' => 'Jenis Susu'],
            ['selection_type' => 'multiple', 'is_required' => false]
        );
        $susu->modifiers()->createMany([
            ['name' => 'Full Cream', 'extra_price' => 0, 'is_default' => true],
            ['name' => 'Oat Milk', 'extra_price' => 5000, 'is_default' => false],
        ]);
    }
}
