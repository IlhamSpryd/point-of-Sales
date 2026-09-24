<?php

namespace Database\Seeders\Pos;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '1G');

        $now = Carbon::now();
        $startDate = $now->copy()->subDays(365);

        $allUserIds = DB::table('users')->pluck('id')->toArray();
        $kasirIds = [2, 9, 10, 11, 12, 13, 14, 15];
        $managerIds = [1, 3, 5, 6, 7, 8];
        $inventoryIds = [32, 33];

        // Activity templates
        $activities = [
            // Login/Logout (most common)
            ['action' => 'login',          'subject_type' => 'App\\Models\\User', 'weight' => 25, 'users' => 'all',
                'desc' => 'User logged in', 'old' => null, 'new' => fn () => json_encode(['ip' => '192.168.1.'.rand(1, 254)])],

            ['action' => 'logout',         'subject_type' => 'App\\Models\\User', 'weight' => 15, 'users' => 'all',
                'desc' => 'User logged out', 'old' => null, 'new' => null],

            // Order operations
            ['action' => 'create_order',   'subject_type' => 'App\\Models\\Order', 'weight' => 20, 'users' => 'kasir',
                'desc' => 'Created new order', 'old' => null, 'new' => fn () => json_encode(['order_code' => 'KSN-'.date('Ymd').'-'.str_pad(rand(1, 300), 4, '0', STR_PAD_LEFT), 'amount' => rand(30, 300) * 1000])],

            ['action' => 'void_order',     'subject_type' => 'App\\Models\\Order', 'weight' => 2, 'users' => 'manager',
                'desc' => 'Voided order', 'old' => fn () => json_encode(['status' => 'completed']), 'new' => fn () => json_encode(['status' => 'voided', 'reason' => ['Customer complaint', 'Wrong order', 'Kitchen error'][rand(0, 2)]])],

            ['action' => 'apply_discount', 'subject_type' => 'App\\Models\\Order', 'weight' => 5, 'users' => 'kasir',
                'desc' => 'Applied discount to order', 'old' => fn () => json_encode(['discount_id' => null]), 'new' => fn () => json_encode(['discount_id' => rand(1, 15), 'discount_amount' => rand(10, 50) * 1000])],

            // Shift operations
            ['action' => 'open_shift',     'subject_type' => 'App\\Models\\Shift', 'weight' => 8, 'users' => 'kasir',
                'desc' => 'Opened cash register shift', 'old' => null, 'new' => fn () => json_encode(['opening_balance' => rand(5, 15) * 100000])],

            ['action' => 'close_shift',    'subject_type' => 'App\\Models\\Shift', 'weight' => 8, 'users' => 'kasir',
                'desc' => 'Closed cash register shift', 'old' => fn () => json_encode(['status' => 'open']), 'new' => fn () => json_encode(['status' => 'closed', 'closing_balance' => rand(10, 50) * 100000, 'difference' => rand(-5, 5) * 10000])],

            // Inventory
            ['action' => 'stock_adjustment', 'subject_type' => 'App\\Models\\Ingredient', 'weight' => 5, 'users' => 'inventory',
                'desc' => 'Adjusted ingredient stock', 'old' => fn () => json_encode(['stock' => rand(100, 5000)]), 'new' => fn () => json_encode(['stock' => rand(5000, 20000), 'reason' => 'Restock delivery'])],

            // Product management
            ['action' => 'update_product', 'subject_type' => 'App\\Models\\Product', 'weight' => 3, 'users' => 'manager',
                'desc' => 'Updated product details', 'old' => fn () => json_encode(['price' => rand(20, 80) * 1000]), 'new' => fn () => json_encode(['price' => rand(20, 80) * 1000])],

            ['action' => 'toggle_product', 'subject_type' => 'App\\Models\\Product', 'weight' => 2, 'users' => 'manager',
                'desc' => 'Toggled product availability', 'old' => fn () => json_encode(['is_active' => rand(0, 1)]), 'new' => fn () => json_encode(['is_active' => rand(0, 1)])],

            // Customer
            ['action' => 'create_customer', 'subject_type' => 'App\\Models\\Customer', 'weight' => 4, 'users' => 'kasir',
                'desc' => 'Registered new customer', 'old' => null, 'new' => fn () => json_encode(['name' => 'Customer '.rand(1, 1000)])],

            // Payment
            ['action' => 'process_payment', 'subject_type' => 'App\\Models\\Payment', 'weight' => 3, 'users' => 'kasir',
                'desc' => 'Processed payment', 'old' => null, 'new' => fn () => json_encode(['method' => ['cash', 'qris', 'debit'][rand(0, 2)], 'amount' => rand(30, 300) * 1000])],
        ];

        $totalWeight = array_sum(array_column($activities, 'weight'));
        $targetRows = 8000;

        DB::table('activity_logs')->delete();
        $rows = [];
        $total = 0;

        $bar = $this->command->getOutput()->createProgressBar($targetRows);
        $bar->setFormat(' ActivityLog: %current%/%max% [%bar%] %percent:3s%%');

        for ($i = 0; $i < $targetRows; $i++) {
            // Pick activity by weight
            $roll = rand(1, $totalWeight);
            $cumulative = 0;
            $activity = $activities[0];
            foreach ($activities as $a) {
                $cumulative += $a['weight'];
                if ($roll <= $cumulative) {
                    $activity = $a;
                    break;
                }
            }

            // Pick user based on activity role
            $userId = match ($activity['users']) {
                'kasir' => $kasirIds[array_rand($kasirIds)],
                'manager' => $managerIds[array_rand($managerIds)],
                'inventory' => $inventoryIds[array_rand($inventoryIds)],
                default => $allUserIds[array_rand($allUserIds)],
            };

            $logTime = $startDate->copy()->addSeconds(rand(0, 365 * 86400));

            $rows[] = [
                'user_id' => $userId,
                'action' => $activity['action'],
                'subject_type' => $activity['subject_type'],
                'subject_id' => rand(1, 5000),
                'description' => $activity['desc'],
                'old_values' => is_callable($activity['old']) ? ($activity['old'])() : $activity['old'],
                'new_values' => is_callable($activity['new']) ? ($activity['new'])() : $activity['new'],
                'ip_address' => '192.168.1.'.rand(1, 254),
                'created_at' => $logTime,
                'updated_at' => $logTime,
            ];
            $total++;

            if (count($rows) >= 1000) {
                DB::table('activity_logs')->insert($rows);
                $rows = [];
            }

            $bar->advance();
        }

        if (! empty($rows)) {
            DB::table('activity_logs')->insert($rows);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("  ✓ Activity logs seeded: {$total}");
    }
}
