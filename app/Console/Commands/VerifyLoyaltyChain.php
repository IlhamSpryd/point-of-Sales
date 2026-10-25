<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DB-014: Command verifikasi berkala untuk memeriksa keutuhan rantai hash loyalty_ledger.
 */
class VerifyLoyaltyChain extends Command
{
    protected $signature = 'pos:verify-loyalty-chain {--customer_id= : Verifikasi untuk satu customer tertentu}';

    protected $description = 'Verifikasi keutuhan hash-chain (prev_hash -> entry_hash) pada tabel loyalty_ledger';

    public function handle(): int
    {
        $this->info('Memulai verifikasi keutuhan rantai hash loyalty_ledger...');

        $customerId = $this->option('customer_id');

        $query = DB::table('loyalty_ledger')
            ->select('id', 'tenant_id', 'customer_id', 'order_id', 'type', 'points', 'balance_after', 'reference', 'prev_hash', 'entry_hash', 'created_at')
            ->orderBy('customer_id')
            ->orderBy('id');

        if ($customerId) {
            $query->where('customer_id', (int) $customerId);
        }

        $entries = $query->cursor();

        $currentCustomer = null;
        $expectedPrevHash = null;
        $brokenChains = [];
        $totalChecked = 0;

        foreach ($entries as $row) {
            $totalChecked++;

            if ($currentCustomer !== $row->customer_id) {
                // Berganti customer: baris pertama harus memiliki prev_hash = null
                $currentCustomer = $row->customer_id;
                $expectedPrevHash = null;
            }

            if ($row->prev_hash !== $expectedPrevHash) {
                $brokenChains[] = [
                    'id' => $row->id,
                    'customer_id' => $row->customer_id,
                    'actual_prev_hash' => $row->prev_hash ?? 'NULL',
                    'expected_prev_hash' => $expectedPrevHash ?? 'NULL',
                    'created_at' => (string) $row->created_at,
                ];
            }

            $expectedPrevHash = $row->entry_hash;
        }

        if (count($brokenChains) > 0) {
            $this->error('Ditemukan '.count($brokenChains).' baris dengan rantai hash terputus:');
            $this->table(['ID', 'Customer ID', 'Actual Prev Hash', 'Expected Prev Hash', 'Created At'], array_slice($brokenChains, 0, 20));

            return self::FAILURE;
        }

        $this->info("Verifikasi selesai: {$totalChecked} rekaman loyalty_ledger terverifikasi utuh tanpa anomali.");

        return self::SUCCESS;
    }
}
