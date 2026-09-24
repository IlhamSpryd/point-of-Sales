<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModifierIngredientSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Link modifiers to their ingredient costs
        // Modifier IDs (from original + new seeder):
        // Original: 1=Ice, 2=Hot, 3=Normal Sugar, 4=Less Sugar, 5=No Sugar, 6=Full Cream, 7=Oat Milk
        // New modifiers start from 8+ (actual IDs depend on insertion order)

        // Fetch modifier IDs by name for reliability
        $modifiers = DB::table('modifiers')
            ->select('id', 'name', 'modifier_group_id')
            ->get()
            ->keyBy('name');

        // Ingredient IDs: 5=Susu UHT, 6=Oat Milk, 7=Almond Milk, 8=Santan, 22=Whipped Cream, 23=Boba, 1=Espresso Beans
        $links = [
            'Full Cream' => [['ingredient_id' => 5, 'quantity_required' => 200]],
            'Oat Milk' => [['ingredient_id' => 6, 'quantity_required' => 200]],
            'Almond Milk' => [['ingredient_id' => 7, 'quantity_required' => 200]],
            'Coconut Milk' => [['ingredient_id' => 8, 'quantity_required' => 200]],
            'Whipped Cream' => [['ingredient_id' => 22, 'quantity_required' => 30]],
            'Boba' => [['ingredient_id' => 23, 'quantity_required' => 50]],
            'Extra Shot' => [['ingredient_id' => 1, 'quantity_required' => 9]],
            'Double Shot' => [['ingredient_id' => 1, 'quantity_required' => 9]],
            'Triple Shot' => [['ingredient_id' => 1, 'quantity_required' => 18]],
            'Extra Rice' => [['ingredient_id' => 32, 'quantity_required' => 150]],
            'French Fries Side' => [['ingredient_id' => 41, 'quantity_required' => 100]],
        ];

        $rows = [];
        foreach ($links as $modName => $ingredients) {
            $mod = $modifiers->get($modName);
            if (! $mod) {
                continue;
            }
            foreach ($ingredients as $ing) {
                $rows[] = [
                    'modifier_id' => $mod->id,
                    'ingredient_id' => $ing['ingredient_id'],
                    'quantity_required' => $ing['quantity_required'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('modifier_ingredients')->delete();
        if (! empty($rows)) {
            DB::table('modifier_ingredients')->insert($rows);
        }

        $this->command->info('  ✓ Modifier ingredients seeded: '.count($rows));
    }
}
