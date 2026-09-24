<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModifierGroupProductSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Group IDs:
        // 1=Pilihan Suhu, 2=Tingkat Gula, 3=Jenis Susu, 4=Ukuran Cup, 5=Level Espresso
        // 6=Topping, 7=Tingkat Pedas, 8=Side Dish

        // Product ranges:
        // 1-15: Kopi Signature, 16-27: Kopi Klasik, 28-39: Non-Coffee, 40-51: Teh & Matcha
        // 52-61: Mocktail, 62-76: Main Nusantara, 77-88: Main Western, 89-98: Rice Bowl
        // 99-113: Snack, 114-125: Dessert, 126-133: Add-on

        $links = [];

        // All beverages (1-61) get Suhu, Gula, Ukuran Cup
        for ($pid = 1; $pid <= 61; $pid++) {
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 1]; // Suhu
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 2]; // Gula
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 4]; // Ukuran Cup
        }

        // Coffee drinks (1-27) get Jenis Susu, Level Espresso, Topping
        for ($pid = 1; $pid <= 27; $pid++) {
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 3]; // Jenis Susu
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 5]; // Level Espresso
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 6]; // Topping
        }

        // Non-coffee & Teh (28-51) get Topping
        for ($pid = 28; $pid <= 51; $pid++) {
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 6]; // Topping
        }

        // Teh & Matcha with milk (40,41,47,48,49,50,51) get Jenis Susu
        foreach ([40, 41, 47, 48, 49, 50, 51] as $pid) {
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 3]; // Jenis Susu
        }

        // Spicy food items get Tingkat Pedas
        $spicyProducts = [62, 63, 64, 65, 66, 69, 70, 71, 73, 75, 80, 93, 96, 98];
        foreach ($spicyProducts as $pid) {
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 7]; // Tingkat Pedas
        }

        // Main courses & Rice Bowls get Side Dish
        for ($pid = 62; $pid <= 98; $pid++) {
            $links[] = ['product_id' => $pid, 'modifier_group_id' => 8]; // Side Dish
        }

        // De-duplicate
        $unique = [];
        $rows = [];
        foreach ($links as $l) {
            $key = $l['product_id'].'-'.$l['modifier_group_id'];
            if (! isset($unique[$key])) {
                $unique[$key] = true;
                $rows[] = array_merge($l, ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        DB::table('modifier_group_product')->delete();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('modifier_group_product')->insert($chunk);
        }

        $this->command->info('  ✓ Modifier-product links seeded: '.count($rows));
    }
}
