<?php

namespace Database\Seeders\Pos;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $startDate = $now->copy()->subDays(365);

        // Kasir user IDs: existing kasir (id 2) + 7 new kasirs (IDs 9-15 based on UserSeeder order)
        // UserSeeder order after existing 4: Manager(5), Supervisor(6,7,8), Kasir(9,10,11,12,13,14,15), Barista(16-21), Waiter(22-27), Cook(28-31), Inventory(32,33)
        $kasirIds = [2, 9, 10, 11, 12, 13, 14, 15];
        $supervisorIds = [6, 7, 8];

        $shiftDefinitions = [
            ['label' => 'Pagi',   'start' => '07:00', 'end' => '15:00'],
            ['label' => 'Siang',  'start' => '15:00', 'end' => '19:00'],
            ['label' => 'Malam',  'start' => '19:00', 'end' => '23:00'],
        ];

        $rows = [];
        $date = $startDate->copy();
        $today = $now->copy()->startOfDay();

        while ($date->lte($today)) {
            // For each shift slot, assign 2-3 kasirs
            foreach ($shiftDefinitions as $def) {
                // Pick 2-3 random kasirs for this shift
                $shuffled = $kasirIds;
                shuffle($shuffled);
                $kasirCount = ($def['label'] === 'Malam') ? 3 : rand(2, 3);
                $selectedKasirs = array_slice($shuffled, 0, $kasirCount);

                foreach ($selectedKasirs as $userId) {
                    $openedAt = Carbon::parse($date->format('Y-m-d').' '.$def['start'], 'Asia/Jakarta')
                        ->addMinutes(rand(-5, 10))
                        ->setTimezone('UTC');

                    $closedAt = Carbon::parse($date->format('Y-m-d').' '.$def['end'], 'Asia/Jakarta')
                        ->addMinutes(rand(-5, 15))
                        ->setTimezone('UTC');

                    $isToday = $date->isSameDay($today);
                    $isLastShift = $def['label'] === 'Malam';
                    $shouldBeOpen = $isToday && ($def['label'] === 'Malam' || ($def['label'] === 'Siang' && $now->hour < 19));

                    $openingBalance = rand(5, 15) * 100000; // 500k - 1.5M
                    $cashSales = rand(500000, 3000000);
                    $expectedCash = $openingBalance + $cashSales;
                    $diff = rand(-5, 5) * 10000; // -50k to +50k, mostly 0
                    if (rand(1, 100) <= 70) {
                        $diff = 0;
                    }
                    $closingBalance = $expectedCash + $diff;

                    $status = 'closed';
                    if ($shouldBeOpen) {
                        $status = 'open';
                        $closedAt = null;
                        $closingBalance = null;
                        $expectedCash = null;
                        $diff = null;
                    }

                    $rows[] = [
                        'user_id' => $userId,
                        'opening_balance' => $openingBalance,
                        'closing_balance' => $closingBalance,
                        'expected_cash' => $expectedCash,
                        'cash_difference' => $diff,
                        'status' => $status,
                        'opened_at' => $openedAt,
                        'closed_at' => $closedAt,
                        'notes' => $status === 'closed' ? 'Shift '.$def['label'].' '.$date->format('d M Y') : null,
                        'created_at' => $openedAt,
                        'updated_at' => $closedAt ?? $openedAt,
                    ];
                }
            }

            $date->addDay();
        }

        // Ensure only 1 open shift per user — close extras
        $openByUser = [];
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if ($rows[$i]['status'] === 'open') {
                $uid = $rows[$i]['user_id'];
                if (isset($openByUser[$uid])) {
                    // Close this one
                    $rows[$i]['status'] = 'closed';
                    $rows[$i]['closed_at'] = $rows[$i]['opened_at']->copy()->addHours(4);
                    $ob = $rows[$i]['opening_balance'];
                    $rows[$i]['expected_cash'] = $ob + rand(500000, 2000000);
                    $rows[$i]['closing_balance'] = $rows[$i]['expected_cash'];
                    $rows[$i]['cash_difference'] = 0;
                    $rows[$i]['updated_at'] = $rows[$i]['closed_at'];
                } else {
                    $openByUser[$uid] = $i;
                }
            }
        }

        DB::table('shifts')->delete();

        $bar = $this->command->getOutput()->createProgressBar(count($rows));
        $bar->setFormat(' Shifts: %current%/%max% [%bar%] %percent:3s%%');

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('shifts')->insert($chunk);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('  ✓ Shifts seeded: '.count($rows));
    }
}
