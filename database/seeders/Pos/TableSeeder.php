<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $tables = [
            // Indoor AC (10 tables)
            ['code' => 'TBL-01', 'name' => 'IN-01', 'capacity' => 2,  'area' => 'Indoor AC'],
            ['code' => 'TBL-02', 'name' => 'IN-02', 'capacity' => 2,  'area' => 'Indoor AC'],
            ['code' => 'TBL-03', 'name' => 'IN-03', 'capacity' => 4,  'area' => 'Indoor AC'],
            ['code' => 'TBL-04', 'name' => 'IN-04', 'capacity' => 4,  'area' => 'Indoor AC'],
            ['code' => 'TBL-05', 'name' => 'IN-05', 'capacity' => 4,  'area' => 'Indoor AC'],
            ['code' => 'TBL-06', 'name' => 'IN-06', 'capacity' => 6,  'area' => 'Indoor AC'],
            ['code' => 'TBL-07', 'name' => 'IN-07', 'capacity' => 6,  'area' => 'Indoor AC'],
            ['code' => 'TBL-08', 'name' => 'IN-08', 'capacity' => 4,  'area' => 'Indoor AC'],
            ['code' => 'TBL-09', 'name' => 'IN-09', 'capacity' => 2,  'area' => 'Indoor AC'],
            ['code' => 'TBL-10', 'name' => 'IN-10', 'capacity' => 4,  'area' => 'Indoor AC'],

            // Semi-Outdoor (5 tables)
            ['code' => 'TBL-11', 'name' => 'SO-01', 'capacity' => 4,  'area' => 'Semi-Outdoor'],
            ['code' => 'TBL-12', 'name' => 'SO-02', 'capacity' => 4,  'area' => 'Semi-Outdoor'],
            ['code' => 'TBL-13', 'name' => 'SO-03', 'capacity' => 6,  'area' => 'Semi-Outdoor'],
            ['code' => 'TBL-14', 'name' => 'SO-04', 'capacity' => 2,  'area' => 'Semi-Outdoor'],
            ['code' => 'TBL-15', 'name' => 'SO-05', 'capacity' => 8,  'area' => 'Semi-Outdoor'],

            // Outdoor Smoker (4 tables)
            ['code' => 'TBL-16', 'name' => 'OD-01', 'capacity' => 4,  'area' => 'Outdoor Smoker'],
            ['code' => 'TBL-17', 'name' => 'OD-02', 'capacity' => 4,  'area' => 'Outdoor Smoker'],
            ['code' => 'TBL-18', 'name' => 'OD-03', 'capacity' => 2,  'area' => 'Outdoor Smoker'],
            ['code' => 'TBL-19', 'name' => 'OD-04', 'capacity' => 6,  'area' => 'Outdoor Smoker'],

            // VIP Room (3 tables)
            ['code' => 'TBL-20', 'name' => 'VP-01', 'capacity' => 8,  'area' => 'VIP Room'],
            ['code' => 'TBL-21', 'name' => 'VP-02', 'capacity' => 10, 'area' => 'VIP Room'],
            ['code' => 'TBL-22', 'name' => 'VP-03', 'capacity' => 6,  'area' => 'VIP Room'],

            // Bar Area (4 tables)
            ['code' => 'TBL-23', 'name' => 'BR-01', 'capacity' => 2,  'area' => 'Bar Area'],
            ['code' => 'TBL-24', 'name' => 'BR-02', 'capacity' => 2,  'area' => 'Bar Area'],
            ['code' => 'TBL-25', 'name' => 'BR-03', 'capacity' => 2,  'area' => 'Bar Area'],
            ['code' => 'TBL-26', 'name' => 'BR-04', 'capacity' => 2,  'area' => 'Bar Area'],

            // Mezzanine (4 tables)
            ['code' => 'TBL-27', 'name' => 'MZ-01', 'capacity' => 4,  'area' => 'Mezzanine'],
            ['code' => 'TBL-28', 'name' => 'MZ-02', 'capacity' => 4,  'area' => 'Mezzanine'],
            ['code' => 'TBL-29', 'name' => 'MZ-03', 'capacity' => 6,  'area' => 'Mezzanine'],
            ['code' => 'TBL-30', 'name' => 'MZ-04', 'capacity' => 8,  'area' => 'Mezzanine'],
        ];

        $rows = [];
        foreach ($tables as $t) {
            $rows[] = [
                'table_code' => $t['code'],
                'table_name' => $t['name'],
                'capacity' => $t['capacity'],
                'area' => $t['area'],
                'is_active' => 1,
                'operational_status' => 'available',
                'secure_token' => Str::uuid()->toString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tables')->delete();
        DB::table('tables')->insert($rows);

        $this->command->info('  ✓ Tables seeded: '.count($rows).' (6 areas)');
    }
}
