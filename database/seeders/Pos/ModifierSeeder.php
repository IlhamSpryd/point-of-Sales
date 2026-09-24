<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModifierSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Keep existing 3 groups (1=Pilihan Suhu, 2=Tingkat Gula, 3=Jenis Susu) and add new ones.
        // Existing modifiers (1-7) are already seeded by the original ModifierSeeder.

        $newGroups = [
            ['id' => 4, 'name' => 'Ukuran Cup',      'selection_type' => 'single',   'is_required' => false, 'min_select' => 1, 'max_select' => 1],
            ['id' => 5, 'name' => 'Level Espresso',   'selection_type' => 'single',   'is_required' => false, 'min_select' => 1, 'max_select' => 1],
            ['id' => 6, 'name' => 'Topping',          'selection_type' => 'multiple',  'is_required' => false, 'min_select' => 0, 'max_select' => 3],
            ['id' => 7, 'name' => 'Tingkat Pedas',    'selection_type' => 'single',   'is_required' => false, 'min_select' => 1, 'max_select' => 1],
            ['id' => 8, 'name' => 'Side Dish',        'selection_type' => 'multiple',  'is_required' => false, 'min_select' => 0, 'max_select' => 2],
        ];

        foreach ($newGroups as $g) {
            DB::table('modifier_groups')->updateOrInsert(
                ['name' => $g['name']],
                array_merge($g, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        // Fetch actual group IDs
        $groupIds = DB::table('modifier_groups')->pluck('id', 'name');

        $newModifiers = [
            // Ukuran Cup
            ['group' => 'Ukuran Cup',     'name' => 'Regular',      'extra_price' => 0,     'is_default' => 1],
            ['group' => 'Ukuran Cup',     'name' => 'Large',        'extra_price' => 8000,  'is_default' => 0],

            // Level Espresso
            ['group' => 'Level Espresso', 'name' => 'Single Shot',  'extra_price' => 0,     'is_default' => 1],
            ['group' => 'Level Espresso', 'name' => 'Double Shot',  'extra_price' => 5000,  'is_default' => 0],
            ['group' => 'Level Espresso', 'name' => 'Triple Shot',  'extra_price' => 9000,  'is_default' => 0],

            // Topping
            ['group' => 'Topping',        'name' => 'Whipped Cream', 'extra_price' => 5000,  'is_default' => 0],
            ['group' => 'Topping',        'name' => 'Boba',         'extra_price' => 6000,  'is_default' => 0],
            ['group' => 'Topping',        'name' => 'Extra Shot',   'extra_price' => 7000,  'is_default' => 0],
            ['group' => 'Topping',        'name' => 'Crumble',      'extra_price' => 5000,  'is_default' => 0],
            ['group' => 'Topping',        'name' => 'Cheese Foam',  'extra_price' => 8000,  'is_default' => 0],

            // Tingkat Pedas
            ['group' => 'Tingkat Pedas',  'name' => 'Level 1 (Mild)',   'extra_price' => 0, 'is_default' => 1],
            ['group' => 'Tingkat Pedas',  'name' => 'Level 2',          'extra_price' => 0, 'is_default' => 0],
            ['group' => 'Tingkat Pedas',  'name' => 'Level 3',          'extra_price' => 0, 'is_default' => 0],
            ['group' => 'Tingkat Pedas',  'name' => 'Level 4',          'extra_price' => 0, 'is_default' => 0],
            ['group' => 'Tingkat Pedas',  'name' => 'Level 5 (Extreme)', 'extra_price' => 0, 'is_default' => 0],

            // Side Dish
            ['group' => 'Side Dish',      'name' => 'French Fries Side',  'extra_price' => 10000, 'is_default' => 0],
            ['group' => 'Side Dish',      'name' => 'Side Salad',         'extra_price' => 10000, 'is_default' => 0],
            ['group' => 'Side Dish',      'name' => 'Extra Rice',         'extra_price' => 8000,  'is_default' => 0],

            // Also add to existing Jenis Susu group
            ['group' => 'Jenis Susu',    'name' => 'Almond Milk',  'extra_price' => 7000,  'is_default' => 0],
            ['group' => 'Jenis Susu',    'name' => 'Coconut Milk', 'extra_price' => 5000,  'is_default' => 0],
        ];

        foreach ($newModifiers as $m) {
            $gid = $groupIds[$m['group']] ?? null;
            if (! $gid) {
                continue;
            }

            DB::table('modifiers')->updateOrInsert(
                ['modifier_group_id' => $gid, 'name' => $m['name']],
                [
                    'modifier_group_id' => $gid,
                    'name' => $m['name'],
                    'extra_price' => $m['extra_price'],
                    'is_default' => $m['is_default'],
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->command->info('  ✓ Modifier groups & modifiers seeded (5 new groups, 20 new modifiers)');
    }
}
