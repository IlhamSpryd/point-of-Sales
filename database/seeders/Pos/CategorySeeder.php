<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $categories = [
            ['category_code' => 'KSN-CAT-001', 'category_name' => 'Kopi Signature'],
            ['category_code' => 'KSN-CAT-002', 'category_name' => 'Kopi Klasik'],
            ['category_code' => 'KSN-CAT-003', 'category_name' => 'Non-Coffee'],
            ['category_code' => 'KSN-CAT-004', 'category_name' => 'Teh & Matcha'],
            ['category_code' => 'KSN-CAT-005', 'category_name' => 'Mocktail & Soda'],
            ['category_code' => 'KSN-CAT-006', 'category_name' => 'Main Course Nusantara'],
            ['category_code' => 'KSN-CAT-007', 'category_name' => 'Main Course Western'],
            ['category_code' => 'KSN-CAT-008', 'category_name' => 'Rice Bowl'],
            ['category_code' => 'KSN-CAT-009', 'category_name' => 'Snack & Appetizer'],
            ['category_code' => 'KSN-CAT-010', 'category_name' => 'Dessert'],
            ['category_code' => 'KSN-CAT-011', 'category_name' => 'Add-on & Topping'],
        ];

        foreach ($categories as $cat) {
            DB::table('categories')->updateOrInsert(
                ['category_code' => $cat['category_code']],
                array_merge($cat, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $this->command->info('  ✓ Categories seeded: '.count($categories));
    }
}
