<?php

namespace Database\Seeders\Pos;

use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        $now = now();
        $totalCustomers = 1000;
        $softDeleteRate = 0.15;

        $usedPhones = [];
        $usedEmails = [];
        $rows = [];

        for ($i = 1; $i <= $totalCustomers; $i++) {
            do {
                $phone = '08'.$faker->numerify('##########');
            } while (in_array($phone, $usedPhones, true));
            $usedPhones[] = $phone;

            do {
                $email = strtolower(preg_replace('/[^a-zA-Z]/', '', $faker->firstName())) . $i . '@' . $faker->freeEmailDomain();
            } while (in_array($email, $usedEmails, true));
            $usedEmails[] = $email;

            $joinDate = $now->copy()->subDays($faker->numberBetween(1, 365));
            $isSoftDeleted = $faker->boolean($softDeleteRate * 100);

            $rows[] = [
                'customer_code' => sprintf('KSN-CUS-%04d', $i),
                'name' => $faker->name(),
                'phone' => $phone,
                'email' => $email,
                'date_of_birth' => $faker->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
                'join_date' => $joinDate->format('Y-m-d'),
                'is_active' => $isSoftDeleted ? 0 : 1,
                'deleted_at' => $isSoftDeleted ? $now->copy()->subDays($faker->numberBetween(1, 90)) : null,
                'created_at' => $joinDate,
                'updated_at' => $now,
            ];
        }

        DB::table('customers')->delete();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $this->command->info('  ✓ Customers seeded: '.$totalCustomers.' ('.round($softDeleteRate * 100).'% soft-deleted)');
    }
}
