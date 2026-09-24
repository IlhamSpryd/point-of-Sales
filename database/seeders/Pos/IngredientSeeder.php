<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IngredientSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $ingredients = [
            // Coffee & Beverage base
            ['code' => 'ING-001', 'name' => 'Espresso Beans House Blend',   'unit' => 'gram',  'cost' => 0.45, 'reorder' => 5000],
            ['code' => 'ING-002', 'name' => 'Arabica Gayo Single Origin',   'unit' => 'gram',  'cost' => 0.65, 'reorder' => 3000],
            ['code' => 'ING-003', 'name' => 'Robusta Lampung',              'unit' => 'gram',  'cost' => 0.30, 'reorder' => 3000],
            ['code' => 'ING-004', 'name' => 'Biji Kopi Decaf',              'unit' => 'gram',  'cost' => 0.80, 'reorder' => 2000],
            ['code' => 'ING-005', 'name' => 'Susu UHT Full Cream',          'unit' => 'ml',    'cost' => 0.02, 'reorder' => 50000],
            ['code' => 'ING-006', 'name' => 'Oat Milk',                     'unit' => 'ml',    'cost' => 0.06, 'reorder' => 20000],
            ['code' => 'ING-007', 'name' => 'Almond Milk',                  'unit' => 'ml',    'cost' => 0.08, 'reorder' => 15000],
            ['code' => 'ING-008', 'name' => 'Santan Kelapa',                'unit' => 'ml',    'cost' => 0.03, 'reorder' => 10000],
            ['code' => 'ING-009', 'name' => 'Gula Aren Cair',               'unit' => 'ml',    'cost' => 0.05, 'reorder' => 10000],
            ['code' => 'ING-010', 'name' => 'Gula Pasir',                   'unit' => 'gram',  'cost' => 0.015, 'reorder' => 20000],
            ['code' => 'ING-011', 'name' => 'Sirup Vanilla',                'unit' => 'ml',    'cost' => 0.10, 'reorder' => 5000],
            ['code' => 'ING-012', 'name' => 'Sirup Hazelnut',               'unit' => 'ml',    'cost' => 0.10, 'reorder' => 5000],
            ['code' => 'ING-013', 'name' => 'Sirup Caramel',                'unit' => 'ml',    'cost' => 0.10, 'reorder' => 5000],
            ['code' => 'ING-014', 'name' => 'Sirup Butterscotch',           'unit' => 'ml',    'cost' => 0.12, 'reorder' => 3000],
            ['code' => 'ING-015', 'name' => 'Matcha Powder Uji',            'unit' => 'gram',  'cost' => 1.20, 'reorder' => 2000],
            ['code' => 'ING-016', 'name' => 'Cokelat Bubuk Premium',        'unit' => 'gram',  'cost' => 0.25, 'reorder' => 5000],
            ['code' => 'ING-017', 'name' => 'Teh Hitam Ceylon',             'unit' => 'gram',  'cost' => 0.35, 'reorder' => 3000],
            ['code' => 'ING-018', 'name' => 'Teh Melati',                   'unit' => 'gram',  'cost' => 0.30, 'reorder' => 3000],
            ['code' => 'ING-019', 'name' => 'Earl Grey Tea',                'unit' => 'gram',  'cost' => 0.40, 'reorder' => 2000],
            ['code' => 'ING-020', 'name' => 'Chamomile Tea',                'unit' => 'gram',  'cost' => 0.50, 'reorder' => 2000],
            ['code' => 'ING-021', 'name' => 'Houjicha Powder',              'unit' => 'gram',  'cost' => 1.00, 'reorder' => 1000],
            ['code' => 'ING-022', 'name' => 'Whipped Cream',                'unit' => 'ml',    'cost' => 0.05, 'reorder' => 10000],
            ['code' => 'ING-023', 'name' => 'Boba Tapioca',                 'unit' => 'gram',  'cost' => 0.04, 'reorder' => 10000],

            // Protein & Main ingredients
            ['code' => 'ING-024', 'name' => 'Ayam Fillet',                  'unit' => 'gram',  'cost' => 0.06, 'reorder' => 30000],
            ['code' => 'ING-025', 'name' => 'Daging Sapi',                  'unit' => 'gram',  'cost' => 0.14, 'reorder' => 20000],
            ['code' => 'ING-026', 'name' => 'Ikan Dori Fillet',             'unit' => 'gram',  'cost' => 0.07, 'reorder' => 15000],
            ['code' => 'ING-027', 'name' => 'Ikan Salmon',                  'unit' => 'gram',  'cost' => 0.20, 'reorder' => 10000],
            ['code' => 'ING-028', 'name' => 'Udang',                        'unit' => 'gram',  'cost' => 0.12, 'reorder' => 10000],
            ['code' => 'ING-029', 'name' => 'Cumi',                         'unit' => 'gram',  'cost' => 0.10, 'reorder' => 10000],
            ['code' => 'ING-030', 'name' => 'Telur Ayam',                   'unit' => 'butir', 'cost' => 2.50, 'reorder' => 500],
            ['code' => 'ING-031', 'name' => 'Tahu Putih',                   'unit' => 'gram',  'cost' => 0.015, 'reorder' => 10000],

            // Carbs
            ['code' => 'ING-032', 'name' => 'Beras Premium',                'unit' => 'gram',  'cost' => 0.015, 'reorder' => 50000],
            ['code' => 'ING-033', 'name' => 'Mie Telur',                    'unit' => 'gram',  'cost' => 0.025, 'reorder' => 20000],
            ['code' => 'ING-034', 'name' => 'Spaghetti Pasta',              'unit' => 'gram',  'cost' => 0.04, 'reorder' => 15000],
            ['code' => 'ING-035', 'name' => 'Kwetiau',                      'unit' => 'gram',  'cost' => 0.03, 'reorder' => 10000],
            ['code' => 'ING-036', 'name' => 'Tepung Terigu',                'unit' => 'gram',  'cost' => 0.012, 'reorder' => 30000],
            ['code' => 'ING-037', 'name' => 'Tepung Panir',                 'unit' => 'gram',  'cost' => 0.025, 'reorder' => 15000],
            ['code' => 'ING-038', 'name' => 'Roti Brioche Bun',             'unit' => 'pcs',   'cost' => 5.00, 'reorder' => 200],
            ['code' => 'ING-039', 'name' => 'Roti Tawar',                   'unit' => 'lembar', 'cost' => 1.50, 'reorder' => 500],
            ['code' => 'ING-040', 'name' => 'Tortilla Chips',               'unit' => 'gram',  'cost' => 0.06, 'reorder' => 5000],
            ['code' => 'ING-041', 'name' => 'Kentang',                      'unit' => 'gram',  'cost' => 0.02, 'reorder' => 30000],

            // Dairy & Cheese
            ['code' => 'ING-042', 'name' => 'Keju Cheddar',                 'unit' => 'gram',  'cost' => 0.10, 'reorder' => 5000],
            ['code' => 'ING-043', 'name' => 'Mozzarella',                   'unit' => 'gram',  'cost' => 0.12, 'reorder' => 5000],
            ['code' => 'ING-044', 'name' => 'Mentega',                      'unit' => 'gram',  'cost' => 0.06, 'reorder' => 5000],
            ['code' => 'ING-045', 'name' => 'Mascarpone',                   'unit' => 'gram',  'cost' => 0.15, 'reorder' => 2000],

            // Vegetables & herbs
            ['code' => 'ING-046', 'name' => 'Bawang Putih',                 'unit' => 'gram',  'cost' => 0.04, 'reorder' => 10000],
            ['code' => 'ING-047', 'name' => 'Bawang Merah',                 'unit' => 'gram',  'cost' => 0.04, 'reorder' => 10000],
            ['code' => 'ING-048', 'name' => 'Bawang Bombai',                'unit' => 'gram',  'cost' => 0.03, 'reorder' => 10000],
            ['code' => 'ING-049', 'name' => 'Cabai Merah',                  'unit' => 'gram',  'cost' => 0.06, 'reorder' => 5000],
            ['code' => 'ING-050', 'name' => 'Cabai Rawit',                  'unit' => 'gram',  'cost' => 0.08, 'reorder' => 3000],
            ['code' => 'ING-051', 'name' => 'Tomat',                        'unit' => 'gram',  'cost' => 0.02, 'reorder' => 10000],
            ['code' => 'ING-052', 'name' => 'Lettuce',                      'unit' => 'gram',  'cost' => 0.03, 'reorder' => 5000],
            ['code' => 'ING-053', 'name' => 'Timun',                        'unit' => 'gram',  'cost' => 0.015, 'reorder' => 5000],
            ['code' => 'ING-054', 'name' => 'Daun Mint',                    'unit' => 'gram',  'cost' => 0.20, 'reorder' => 2000],

            // Sauces & Condiments
            ['code' => 'ING-055', 'name' => 'Saus Tomat',                   'unit' => 'ml',    'cost' => 0.03, 'reorder' => 10000],
            ['code' => 'ING-056', 'name' => 'Kecap Manis',                  'unit' => 'ml',    'cost' => 0.02, 'reorder' => 10000],
            ['code' => 'ING-057', 'name' => 'Olive Oil',                    'unit' => 'ml',    'cost' => 0.10, 'reorder' => 5000],
            ['code' => 'ING-058', 'name' => 'Minyak Goreng',                'unit' => 'ml',    'cost' => 0.02, 'reorder' => 30000],
            ['code' => 'ING-059', 'name' => 'Saus Teriyaki',                'unit' => 'ml',    'cost' => 0.05, 'reorder' => 5000],
            ['code' => 'ING-060', 'name' => 'Bumbu Rendang Instan',         'unit' => 'gram',  'cost' => 0.08, 'reorder' => 5000],

            // Fruits
            ['code' => 'ING-061', 'name' => 'Pisang',                       'unit' => 'buah',  'cost' => 2.00, 'reorder' => 300],
            ['code' => 'ING-062', 'name' => 'Lemon',                        'unit' => 'buah',  'cost' => 3.00, 'reorder' => 200],
            ['code' => 'ING-063', 'name' => 'Jeruk Nipis',                  'unit' => 'buah',  'cost' => 1.50, 'reorder' => 300],
            ['code' => 'ING-064', 'name' => 'Stroberi',                     'unit' => 'gram',  'cost' => 0.12, 'reorder' => 5000],
            ['code' => 'ING-065', 'name' => 'Mangga',                       'unit' => 'gram',  'cost' => 0.05, 'reorder' => 5000],
        ];

        $rows = [];
        foreach ($ingredients as $ing) {
            $rows[] = [
                'ingredient_code' => $ing['code'],
                'name' => $ing['name'],
                'unit' => $ing['unit'],
                'current_stock' => rand(5000, 50000) * ($ing['unit'] === 'butir' || $ing['unit'] === 'buah' || $ing['unit'] === 'pcs' || $ing['unit'] === 'lembar' ? 0.1 : 1),
                'cost_per_unit' => $ing['cost'],
                'reorder_level' => $ing['reorder'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('ingredients')->delete();
        DB::table('ingredients')->insert($rows);

        $this->command->info('  ✓ Ingredients seeded: '.count($rows));
    }
}
