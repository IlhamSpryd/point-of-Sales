<?php

namespace Database\Seeders\Pos;

use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        $hashedPassword = Hash::make('password');

        $now = now();
        $usedPins = [];

        $generatePin = function () use (&$usedPins, $faker) {
            do {
                $pin = str_pad((string) $faker->numberBetween(100000, 999999), 6, '0', STR_PAD_LEFT);
            } while (in_array($pin, $usedPins, true));
            $usedPins[] = $pin;

            return $pin;
        };

        // Existing users (id 1–4) — update with employee_id, pin, etc. but don't duplicate
        $existingUpdates = [
            ['email' => 'admin@pos.test', 'employee_id' => 'KSN-EMP-0001', 'pin_hash' => Hash::make($generatePin()), 'phone_number' => '081200000001', 'join_date' => now()->subYears(3)->format('Y-m-d'), 'last_login_at' => $now],
            ['email' => 'kasir@pos.test', 'employee_id' => 'KSN-EMP-0002', 'pin_hash' => Hash::make($generatePin()), 'phone_number' => '081200000002', 'join_date' => now()->subYears(2)->format('Y-m-d'), 'last_login_at' => $now->copy()->subHours(2)],
            ['email' => 'manager@pos.test', 'employee_id' => 'KSN-EMP-0003', 'pin_hash' => Hash::make($generatePin()), 'phone_number' => '081200000003', 'join_date' => now()->subYears(2)->subMonths(6)->format('Y-m-d'), 'last_login_at' => $now->copy()->subHour()],
            ['email' => 'selforder@system.local', 'employee_id' => 'KSN-EMP-0004', 'pin_hash' => Hash::make($generatePin()), 'phone_number' => null, 'join_date' => now()->subYear()->format('Y-m-d'), 'last_login_at' => null],
        ];

        foreach ($existingUpdates as $u) {
            DB::table('users')->where('email', $u['email'])->update([
                'employee_id' => $u['employee_id'],
                'pin_hash' => $u['pin_hash'],
                'phone_number' => $u['phone_number'],
                'join_date' => $u['join_date'],
                'last_login_at' => $u['last_login_at'],
                'updated_at' => $now,
            ]);
        }

        // Role IDs: 1=Owner, 2=Manager, 3=Kasir, 4=Waiter, 5=Barista, 6=Inventory, 7=Supervisor, 8=Cook
        // New employees starting from id 5
        $staff = [
            // 1 more Manager
            ['role_id' => 2, 'count' => 1],
            // 3 Supervisor
            ['role_id' => 7, 'count' => 3],
            // 7 more Kasir (1 already exists as demo)
            ['role_id' => 3, 'count' => 7],
            // 6 Barista
            ['role_id' => 5, 'count' => 6],
            // 6 Waiter
            ['role_id' => 4, 'count' => 6],
            // 4 Cook
            ['role_id' => 8, 'count' => 4],
            // 2 Inventory
            ['role_id' => 6, 'count' => 2],
        ];

        $empCounter = 5;
        $usedEmails = [];

        $rows = [];
        foreach ($staff as $group) {
            for ($i = 0; $i < $group['count']; $i++) {
                $name = $faker->name();
                $empId = sprintf('KSN-EMP-%04d', $empCounter);

                do {
                    $email = strtolower(str_replace(' ', '.', $faker->unique()->firstName())).$empCounter.'@yovelcafe.test';
                } while (in_array($email, $usedEmails, true));
                $usedEmails[] = $email;

                $joinDate = now()->subDays($faker->numberBetween(180, 1095));

                $rows[] = [
                    'employee_id' => $empId,
                    'name' => $name,
                    'phone_number' => '08'.$faker->numerify('##########'),
                    'email' => $email,
                    'email_verified_at' => $now,
                    'password' => $hashedPassword,
                    'pin_code' => $generatePin(),
                    'join_date' => $joinDate->format('Y-m-d'),
                    'role_id' => $group['role_id'],
                    'is_active' => 1,
                    'last_login_at' => $now->copy()->subMinutes($faker->numberBetween(10, 1440)),
                    'created_at' => $joinDate,
                    'updated_at' => $now,
                ];
                $empCounter++;
            }
        }

        // Bulk insert
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $this->command->info('  ✓ Users seeded: '.(count($rows) + 4).' total (4 existing + '.count($rows).' new)');
    }
}
