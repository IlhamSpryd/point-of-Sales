<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LoyaltyTierSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $tiers = [
            ['tier_code' => 'TIER-BRZ', 'name' => 'Bronze',   'min_points' => 0,     'points_multiplier' => 1.00, 'benefits' => json_encode(['discount' => '0%', 'perks' => ['Birthday greeting']])],
            ['tier_code' => 'TIER-SLV', 'name' => 'Silver',   'min_points' => 500,   'points_multiplier' => 1.25, 'benefits' => json_encode(['discount' => '5%', 'perks' => ['Birthday discount 10%', 'Priority seating']])],
            ['tier_code' => 'TIER-GLD', 'name' => 'Gold',     'min_points' => 2500,  'points_multiplier' => 1.50, 'benefits' => json_encode(['discount' => '10%', 'perks' => ['Birthday discount 15%', 'Free dessert monthly', 'Priority seating']])],
            ['tier_code' => 'TIER-PLT', 'name' => 'Platinum', 'min_points' => 10000, 'points_multiplier' => 2.00, 'benefits' => json_encode(['discount' => '15%', 'perks' => ['Birthday discount 25%', 'Free drinks monthly', 'VIP room access', 'Early menu access']])],
            ['tier_code' => 'TIER-DMD', 'name' => 'Diamond',  'min_points' => 25000, 'points_multiplier' => 3.00, 'benefits' => json_encode(['discount' => '20%', 'perks' => ['Birthday free meal', 'Unlimited free drinks', 'VIP room access', 'Chef table experience', 'Personal barista']])],
        ];

        foreach ($tiers as $tier) {
            DB::table('loyalty_tiers')->updateOrInsert(
                ['tier_code' => $tier['tier_code']],
                array_merge($tier, ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        $this->command->info('  ✓ Loyalty tiers seeded: '.count($tiers));
    }
}
