<?php

namespace Database\Seeders\Pos;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashDrawerMovementSeeder extends Seeder
{
    public function run(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $shifts = DB::table('shifts')
            ->select('id', 'user_id', 'opening_balance', 'status', 'opened_at', 'closed_at')
            ->orderBy('opened_at')
            ->get();

        $managerIds = [1, 3, 5, 6, 7, 8];
        $reasons = [
            'restock_change' => ['Kas awal shift', 'Penerimaan tunai penjualan'],
            'bank_deposit' => ['Setor ke brankas', 'Setor ke safe', 'Penyetoran kas malam'],
            'petty_expense' => [
                'Beli es batu', 'Beli galon air', 'Beli tissue & straw',
                'Parkir supplier', 'Beli plastik kemasan', 'Beli cup takeaway',
                'Beli serbet', 'Ganti tabung gas', 'Beli sabun cuci',
            ],
        ];

        DB::table('cash_drawer_movements')->delete();
        $rows = [];
        $total = 0;

        $bar = $this->command->getOutput()->createProgressBar($shifts->count());
        $bar->setFormat(' CashDrawer: %current%/%max% [%bar%] %percent:3s%%');

        foreach ($shifts as $shift) {
            $openedAt = Carbon::parse($shift->opened_at);

            // 1. Opening float
            $rows[] = [
                'shift_id' => $shift->id,
                'type' => 'cash_in',
                'category' => 'restock_change',
                'amount' => $shift->opening_balance,
                'reason' => $reasons['restock_change'][0],
                'receipt_reference' => null,
                'created_by' => $shift->user_id,
                'approved_by' => null,
                'idempotency_key' => Str::uuid()->toString(),
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ];
            $total++;

            // 2. Cash corrections / restock (1-2 entries per shift)
            $correctionCount = rand(1, 2);
            for ($i = 0; $i < $correctionCount; $i++) {
                $rows[] = [
                    'shift_id' => $shift->id,
                    'type' => 'cash_in',
                    'category' => 'correction',
                    'amount' => rand(200000, 2000000),
                    'reason' => $reasons['restock_change'][1],
                    'receipt_reference' => 'BATCH-'.strtoupper(Str::random(6)),
                    'created_by' => $shift->user_id,
                    'approved_by' => null,
                    'idempotency_key' => Str::uuid()->toString(),
                    'created_at' => $openedAt->copy()->addHours(rand(1, 3)),
                    'updated_at' => $openedAt->copy()->addHours(rand(1, 3)),
                ];
                $total++;
            }

            // 3. Bank deposit (0-1 per shift)
            if (rand(1, 100) <= 60) {
                $rows[] = [
                    'shift_id' => $shift->id,
                    'type' => 'cash_out',
                    'category' => 'bank_deposit',
                    'amount' => rand(500000, 2000000),
                    'reason' => $reasons['bank_deposit'][array_rand($reasons['bank_deposit'])],
                    'receipt_reference' => 'DROP-'.strtoupper(Str::random(6)),
                    'created_by' => $shift->user_id,
                    'approved_by' => $managerIds[array_rand($managerIds)],
                    'idempotency_key' => Str::uuid()->toString(),
                    'created_at' => $openedAt->copy()->addHours(rand(2, 6)),
                    'updated_at' => $openedAt->copy()->addHours(rand(2, 6)),
                ];
                $total++;
            }

            // 4. Petty cash (0-2 per shift)
            $pettyCount = rand(0, 2);
            for ($i = 0; $i < $pettyCount; $i++) {
                $rows[] = [
                    'shift_id' => $shift->id,
                    'type' => 'cash_out',
                    'category' => 'petty_expense',
                    'amount' => rand(10000, 100000),
                    'reason' => $reasons['petty_expense'][array_rand($reasons['petty_expense'])],
                    'receipt_reference' => 'PC-'.strtoupper(Str::random(6)),
                    'created_by' => $shift->user_id,
                    'approved_by' => (rand(1, 100) <= 50) ? $managerIds[array_rand($managerIds)] : null,
                    'idempotency_key' => Str::uuid()->toString(),
                    'created_at' => $openedAt->copy()->addHours(rand(1, 5)),
                    'updated_at' => $openedAt->copy()->addHours(rand(1, 5)),
                ];
                $total++;
            }

            if (count($rows) >= 1000) {
                DB::table('cash_drawer_movements')->insert($rows);
                $rows = [];
            }

            $bar->advance();
        }

        if (! empty($rows)) {
            DB::table('cash_drawer_movements')->insert($rows);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("  ✓ Cash drawer movements seeded: {$total}");
    }
}
