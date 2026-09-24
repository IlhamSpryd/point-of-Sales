<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LoyaltyLedgerSeeder: Creates loyalty point earn/redeem entries.
 *
 * IMPORTANT:
 * - loyalty_ledger is append-only (triggers block UPDATE/DELETE).
 * - prev_hash & entry_hash are auto-filled by trigger trg_loyalty_ledger_hash_chain.
 * - trg_loyalty_ledger_sync_account auto-updates customer_loyalty_accounts.
 * - We must insert ONE ROW AT A TIME per customer to maintain hash chain order.
 */
class LoyaltyLedgerSeeder extends Seeder
{
    public function run(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '2G');

        // Drop triggers temporarily for bulk insert, we'll recompute balances
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_hash_chain');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_sync_account');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('loyalty_ledger')->delete();
        DB::table('customer_loyalty_accounts')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Get completed orders with customers, ordered by date
        $orders = DB::table('orders')
            ->where('order_status', 'paid')
            ->whereNotNull('customer_id')
            ->select('id', 'customer_id', 'order_amount', 'created_at', 'user_id')
            ->orderBy('created_at')
            ->get();

        $this->command->info("  Processing {$orders->count()} completed orders with customers for loyalty...");

        // Track balance per customer
        $balances = [];
        $lifetimeEarned = [];
        $ledgerRows = [];
        $totalEntries = 0;

        $bar = $this->command->getOutput()->createProgressBar($orders->count());
        $bar->setFormat(' Loyalty: %current%/%max% [%bar%] %percent:3s%%');

        foreach ($orders as $order) {
            $cid = $order->customer_id;

            if (! isset($balances[$cid])) {
                $balances[$cid] = 0;
                $lifetimeEarned[$cid] = 0;
            }

            // Earn points: 1 point per Rp 10,000 spent
            $earnedPoints = (int) floor($order->order_amount / 10000);
            if ($earnedPoints <= 0) {
                $bar->advance();

                continue;
            }

            $balances[$cid] += $earnedPoints;
            $lifetimeEarned[$cid] += $earnedPoints;

            $prevHash = null;
            // Compute hash chain manually
            $entryHash = hash('sha256', implode('|', [
                $cid,
                $order->id,
                'earn',
                $earnedPoints,
                $balances[$cid],
                $prevHash ?? '',
                $order->created_at,
            ]));

            // Check for last hash
            if (! empty($ledgerRows)) {
                // Find last entry for this customer
                for ($i = count($ledgerRows) - 1; $i >= 0; $i--) {
                    if ($ledgerRows[$i]['customer_id'] === $cid) {
                        $prevHash = $ledgerRows[$i]['entry_hash'];
                        break;
                    }
                }
                // Recompute with correct prev_hash
                $entryHash = hash('sha256', implode('|', [
                    $cid,
                    $order->id,
                    'earn',
                    $earnedPoints,
                    $balances[$cid],
                    $prevHash ?? '',
                    $order->created_at,
                ]));
            }

            $ledgerRows[] = [
                'customer_id' => $cid,
                'order_id' => $order->id,
                'type' => 'earn',
                'points' => $earnedPoints,
                'balance_after' => $balances[$cid],
                'reference' => 'Order #'.$order->id,
                'prev_hash' => $prevHash,
                'entry_hash' => $entryHash,
                'created_by' => $order->user_id,
                'created_at' => $order->created_at,
            ];
            $totalEntries++;

            // Occasional redemption (every ~20 earns, if balance > 100 points)
            if ($totalEntries % 20 === 0 && $balances[$cid] > 100) {
                $redeemPoints = min($balances[$cid], rand(50, 200));
                $balances[$cid] -= $redeemPoints;

                $prevHash = $entryHash; // Previous entry's hash
                $redeemHash = hash('sha256', implode('|', [
                    $cid,
                    $order->id,
                    'redeem',
                    -$redeemPoints,
                    $balances[$cid],
                    $prevHash,
                    $order->created_at,
                ]));

                $ledgerRows[] = [
                    'customer_id' => $cid,
                    'order_id' => $order->id,
                    'type' => 'redeem',
                    'points' => -$redeemPoints,
                    'balance_after' => $balances[$cid],
                    'reference' => 'Redeem for discount',
                    'prev_hash' => $prevHash,
                    'entry_hash' => $redeemHash,
                    'created_by' => $order->user_id,
                    'created_at' => $order->created_at,
                ];
                $totalEntries++;
            }

            // Flush every 1000 rows
            if (count($ledgerRows) >= 1000) {
                DB::table('loyalty_ledger')->insert($ledgerRows);
                $ledgerRows = [];
            }

            $bar->advance();
        }

        // Flush remaining
        if (! empty($ledgerRows)) {
            DB::table('loyalty_ledger')->insert($ledgerRows);
        }

        $bar->finish();
        $this->command->newLine();

        // Now update customer_loyalty_accounts
        $tiers = DB::table('loyalty_tiers')->orderBy('min_points', 'desc')->get();
        $now = now();
        $accountRows = [];

        foreach ($balances as $cid => $balance) {
            $lifetime = $lifetimeEarned[$cid] ?? 0;

            // Determine tier based on lifetime earned
            $tierId = null;
            foreach ($tiers as $tier) {
                if ($lifetime >= $tier->min_points) {
                    $tierId = $tier->id;
                    break;
                }
            }

            $accountRows[] = [
                'customer_id' => $cid,
                'current_points' => $balance,
                'lifetime_points_earned' => $lifetime,
                'current_tier_id' => $tierId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($accountRows, 500) as $chunk) {
            DB::table('customer_loyalty_accounts')->insert($chunk);
        }

        // Recreate triggers
        DB::unprepared("
            CREATE TRIGGER trg_loyalty_ledger_hash_chain BEFORE INSERT ON loyalty_ledger FOR EACH ROW
            BEGIN
                DECLARE last_hash CHAR(64);
                SELECT entry_hash INTO last_hash FROM loyalty_ledger WHERE customer_id = NEW.customer_id ORDER BY id DESC LIMIT 1;
                SET NEW.prev_hash = last_hash;
                SET NEW.entry_hash = SHA2(CONCAT_WS('|', NEW.customer_id, IFNULL(NEW.order_id, ''), NEW.type, NEW.points, NEW.balance_after, IFNULL(last_hash, ''), NOW()), 256);
            END
        ");
        DB::unprepared("
            CREATE TRIGGER trg_loyalty_ledger_no_delete BEFORE DELETE ON loyalty_ledger FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: DELETE dilarang.';
            END
        ");
        DB::unprepared("
            CREATE TRIGGER trg_loyalty_ledger_no_update BEFORE UPDATE ON loyalty_ledger FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: UPDATE dilarang.';
            END
        ");
        DB::unprepared('
            CREATE TRIGGER trg_loyalty_ledger_sync_account AFTER INSERT ON loyalty_ledger FOR EACH ROW
            BEGIN
                INSERT INTO customer_loyalty_accounts (customer_id, current_points, lifetime_points_earned, created_at, updated_at)
                VALUES (NEW.customer_id, NEW.balance_after, GREATEST(NEW.points, 0), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    current_points = NEW.balance_after,
                    lifetime_points_earned = lifetime_points_earned + GREATEST(NEW.points, 0),
                    updated_at = NOW();
            END
        ');

        $this->command->info("  ✓ Loyalty ledger: {$totalEntries} entries, ".count($accountRows).' accounts synced');
    }
}
