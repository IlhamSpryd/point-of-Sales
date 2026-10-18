<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ModifierGroup;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class ModifierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('modifier_groups') || ! Schema::hasTable('modifiers')) {
            return;
        }

        if (app(TenantContext::class)->hasTenant()) {
            $this->seedGroups();

            return;
        }

        $tenant = Tenant::query()->orderBy('id')->first() ?? Tenant::firstOrCreate(['name' => 'Default Tenant']);
        app(TenantContext::class)->runAs($tenant->id, null, fn () => $this->seedGroups());
    }

    private function seedGroups(): void
    {
        // Grup 1: Pilihan Suhu (wajib diisi, hanya boleh pilih satu)
        $suhu = ModifierGroup::firstOrCreate(
            ['name' => 'Pilihan Suhu'],
            [
                'selection_type' => 'single',
                'is_required' => true,
                'min_select' => 1,
                'max_select' => 1,
            ]
        );
        foreach ([
            ['name' => 'Ice', 'extra_price' => 0, 'is_default' => true],
            ['name' => 'Hot', 'extra_price' => 0, 'is_default' => false],
        ] as $modifier) {
            $suhu->modifiers()->updateOrCreate(
                ['name' => $modifier['name']],
                $modifier
            );
        }

        // Grup 2: Tingkat Gula (wajib diisi, hanya boleh pilih satu)
        $gula = ModifierGroup::firstOrCreate(
            ['name' => 'Tingkat Gula'],
            [
                'selection_type' => 'single',
                'is_required' => true,
                'min_select' => 1,
                'max_select' => 1,
            ]
        );
        foreach ([
            ['name' => 'Normal', 'extra_price' => 0, 'is_default' => true],
            ['name' => 'Less Sugar', 'extra_price' => 0, 'is_default' => false],
            ['name' => 'No Sugar', 'extra_price' => 0, 'is_default' => false],
        ] as $modifier) {
            $gula->modifiers()->updateOrCreate(
                ['name' => $modifier['name']],
                $modifier
            );
        }

        // Grup 3: Jenis Susu (opsional, boleh pilih lebih dari satu jenis campuran)
        $susu = ModifierGroup::firstOrCreate(
            ['name' => 'Jenis Susu'],
            [
                'selection_type' => 'multiple',
                'is_required' => false,
                'min_select' => 0,
                'max_select' => 2,
            ]
        );
        foreach ([
            ['name' => 'Full Cream', 'extra_price' => 0, 'is_default' => true],
            ['name' => 'Oat Milk', 'extra_price' => 5000, 'is_default' => false],
        ] as $modifier) {
            $susu->modifiers()->updateOrCreate(
                ['name' => $modifier['name']],
                $modifier
            );
        }
    }
}
