<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $yearAgo = $now->copy()->subYear();

        $discounts = [
            ['code' => 'HAPPYHOUR',    'name' => 'Happy Hour 20%',         'type' => 'percentage', 'value' => 20, 'max_discount_amount' => 30000,  'min_purchase_amount' => 50000,  'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(3)],
            ['code' => 'WEEKEND10',    'name' => 'Weekend Discount 10%',   'type' => 'percentage', 'value' => 10, 'max_discount_amount' => 25000,  'min_purchase_amount' => 80000,  'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(6)],
            ['code' => 'NEWMEMBER',    'name' => 'New Member Rp 15.000',   'type' => 'fixed',      'value' => 15000, 'max_discount_amount' => null, 'min_purchase_amount' => 75000,  'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addYear()],
            ['code' => 'BIRTHDAY',     'name' => 'Birthday Discount 25%',  'type' => 'percentage', 'value' => 25, 'max_discount_amount' => 50000,  'min_purchase_amount' => 0,      'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addYear()],
            ['code' => 'GAJIAN',       'name' => 'Promo Gajian Rp 20.000', 'type' => 'fixed',      'value' => 20000, 'max_discount_amount' => null, 'min_purchase_amount' => 100000, 'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(3)],
            ['code' => 'LOYALTY15',    'name' => 'Loyalty Member 15%',     'type' => 'percentage', 'value' => 15, 'max_discount_amount' => 40000,  'min_purchase_amount' => 60000,  'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(6)],
            ['code' => 'FLASHSALE',    'name' => 'Flash Sale 30%',         'type' => 'percentage', 'value' => 30, 'max_discount_amount' => 50000,  'min_purchase_amount' => 100000, 'valid_from' => $now->copy()->subMonths(2), 'valid_until' => $now->copy()->subMonth()],
            ['code' => 'RAMADAN',      'name' => 'Promo Ramadan 20%',      'type' => 'percentage', 'value' => 20, 'max_discount_amount' => 35000,  'min_purchase_amount' => 80000,  'valid_from' => $now->copy()->subMonths(6), 'valid_until' => $now->copy()->subMonths(5)],
            ['code' => 'LEBARAN',      'name' => 'Diskon Lebaran Rp 25k',  'type' => 'fixed',      'value' => 25000, 'max_discount_amount' => null, 'min_purchase_amount' => 100000, 'valid_from' => $now->copy()->subMonths(5), 'valid_until' => $now->copy()->subMonths(4)->subWeeks(2)],
            ['code' => 'KEMERDEKAAN',  'name' => 'HUT RI 17% OFF',         'type' => 'percentage', 'value' => 17, 'max_discount_amount' => 30000,  'min_purchase_amount' => 50000,  'valid_from' => $now->copy()->subMonths(1)->startOfMonth(), 'valid_until' => $now->copy()->subMonths(1)->endOfMonth()],
            ['code' => 'GRABFOOD10',   'name' => 'GrabFood Partner 10%',   'type' => 'percentage', 'value' => 10, 'max_discount_amount' => 20000,  'min_purchase_amount' => 50000,  'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(6)],
            ['code' => 'GOFOOD15',     'name' => 'GoFood Partner 15%',     'type' => 'percentage', 'value' => 15, 'max_discount_amount' => 25000,  'min_purchase_amount' => 60000,  'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(6)],
            ['code' => 'FIRST50',      'name' => 'First Order Rp 50k',     'type' => 'fixed',      'value' => 50000, 'max_discount_amount' => null, 'min_purchase_amount' => 200000, 'valid_from' => $yearAgo, 'valid_until' => $now->copy()->addMonths(3)],
            ['code' => 'VALENTINE',    'name' => 'Valentine Couple 20%',   'type' => 'percentage', 'value' => 20, 'max_discount_amount' => 40000,  'min_purchase_amount' => 150000, 'valid_from' => $now->copy()->subMonths(7), 'valid_until' => $now->copy()->subMonths(7)->addWeek()],
            ['code' => 'YEAREND',      'name' => 'Year End Sale 25%',      'type' => 'percentage', 'value' => 25, 'max_discount_amount' => 50000,  'min_purchase_amount' => 100000, 'valid_from' => $now->copy()->subMonths(9), 'valid_until' => $now->copy()->subMonths(9)->addWeeks(2)],
        ];

        foreach ($discounts as $d) {
            DB::table('discounts')->updateOrInsert(
                ['code' => $d['code']],
                array_merge($d, ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        $this->command->info('  ✓ Discounts seeded: '.count($discounts));
    }
}
