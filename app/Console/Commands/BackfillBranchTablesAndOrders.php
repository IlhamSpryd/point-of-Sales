<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillBranchTablesAndOrders extends Command
{
    protected $signature = 'pos:backfill-branch-tables-orders {--dry-run : Run reconciliation without persisting changes}';

    protected $description = 'Deterministic backfill and reconciliation for branch table ownership and orders (ADR-009 / Section J.1)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('===============================================================');
        $this->info(' Apeiron POS: Backfill Branch Tables and Orders');
        $this->info(' Mode: '.($isDryRun ? 'DRY-RUN (No changes saved)' : 'PERSIST (Changes will be committed)'));
        $this->info('===============================================================');

        $report = [
            'rule_1_order_inference' => 0,
            'rule_2_single_store_inference' => 0,
            'rule_3_cross_branch_clones' => 0,
            'rule_4_quarantined_orphans' => 0,
            'orders_table_number_backfilled' => 0,
            'tables_qr_token_backfilled' => 0,
        ];

        DB::beginTransaction();

        try {
            // 1. Rekonsiliasi tables.store_id
            $tables = Table::withoutGlobalScopes()->get();

            foreach ($tables as $table) {
                // Periksa apakah qr_token perlu diisi
                if (empty($table->qr_token)) {
                    $report['tables_qr_token_backfilled']++;
                    if (! $isDryRun) {
                        $table->qr_token = bin2hex(random_bytes(32));
                        $table->qr_token_version = $table->qr_token_version ?: 1;
                        $table->saveQuietly();
                    }
                }

                $storeExists = $table->store_id && DB::table('stores')
                    ->where('tenant_id', $table->tenant_id)
                    ->where('id', $table->store_id)
                    ->exists();

                if ($storeExists) {
                    continue; // Meja sudah terikat store valid
                }

                // Cek pesanan terkait
                $associatedStoreIds = Order::withoutGlobalScopes()
                    ->where('tenant_id', $table->tenant_id)
                    ->where('table_id', $table->id)
                    ->distinct()
                    ->pluck('store_id')
                    ->all();

                if (count($associatedStoreIds) === 1) {
                    // ATURAN 1: Inferensi dari Riwayat Order
                    $storeId = (int) $associatedStoreIds[0];
                    $this->line("Rule 1: Table #{$table->id} ('{$table->table_name}') assigned to store #{$storeId} via orders history.");
                    $report['rule_1_order_inference']++;
                    if (! $isDryRun) {
                        $table->store_id = $storeId;
                        $table->saveQuietly();
                    }
                } elseif (empty($associatedStoreIds)) {
                    // Meja belum pernah memiliki pesanan
                    $tenantStoreCount = Store::withoutGlobalScopes()
                        ->where('tenant_id', $table->tenant_id)
                        ->count();

                    if ($tenantStoreCount === 1) {
                        // ATURAN 2: Inferensi Cabang Tunggal
                        $soleStore = Store::withoutGlobalScopes()
                            ->where('tenant_id', $table->tenant_id)
                            ->first();

                        $this->line("Rule 2: Table #{$table->id} ('{$table->table_name}') assigned to sole store #{$soleStore->id}.");
                        $report['rule_2_single_store_inference']++;
                        if (! $isDryRun) {
                            $table->store_id = $soleStore->id;
                            $table->saveQuietly();
                        }
                    } else {
                        // ATURAN 4: Karantina Meja Ambigu (tenant multi-cabang tanpa riwayat order)
                        $this->warn("Rule 4: Table #{$table->id} ('{$table->table_name}') quarantined (tenant #{$table->tenant_id} has {$tenantStoreCount} stores).");
                        $report['rule_4_quarantined_orphans']++;
                        if (! $isDryRun) {
                            $table->is_active = false;
                            $table->operational_status = 'reserved'; // atau disabled
                            $table->saveQuietly();
                        }
                    }
                } else {
                    // ATURAN 3: Pemisahan Konflik Multi-Cabang (1 meja digunakan pesanan di >1 cabang)
                    $this->warn("Rule 3: Table #{$table->id} ('{$table->table_name}') used in multiple stores: ".implode(', ', $associatedStoreIds));
                    $primaryStoreId = (int) $associatedStoreIds[0];
                    $otherStoreIds = array_slice($associatedStoreIds, 1);

                    if (! $isDryRun) {
                        $table->store_id = $primaryStoreId;
                        $table->saveQuietly();

                        foreach ($otherStoreIds as $otherStoreId) {
                            // Klon meja untuk store lain
                            $clonedTable = $table->replicate();
                            $clonedTable->store_id = (int) $otherStoreId;
                            $clonedTable->table_code = 'YVL-TBL-'.str_pad((string) (Table::withoutGlobalScopes()->max('id') + 1), 3, '0', STR_PAD_LEFT);
                            $clonedTable->secure_token = (string) Str::uuid();
                            $clonedTable->qr_token = bin2hex(random_bytes(32));
                            $clonedTable->saveQuietly();

                            // Tautkan kembali pesanan store tersebut ke ID meja yang baru
                            Order::withoutGlobalScopes()
                                ->where('tenant_id', $table->tenant_id)
                                ->where('store_id', $otherStoreId)
                                ->where('table_id', $table->id)
                                ->update(['table_id' => $clonedTable->id]);

                            $report['rule_3_cross_branch_clones']++;
                        }
                    } else {
                        $report['rule_3_cross_branch_clones'] += count($otherStoreIds);
                    }
                }
            }

            // 2. Backfill orders.table_number dari tables.table_name
            $ordersNeedingTableNumber = Order::withoutGlobalScopes()
                ->whereNotNull('table_id')
                ->whereNull('table_number')
                ->count();

            if ($ordersNeedingTableNumber > 0) {
                $report['orders_table_number_backfilled'] = $ordersNeedingTableNumber;
                $this->info("Backfilling table_number for {$ordersNeedingTableNumber} orders...");
                if (! $isDryRun) {
                    DB::statement('
                        UPDATE orders o
                        JOIN tables t ON o.table_id = t.id AND o.tenant_id = t.tenant_id AND o.store_id = t.store_id
                        SET o.table_number = t.table_name
                        WHERE o.table_id IS NOT NULL AND o.table_number IS NULL
                    ');
                }
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->info("\n[DRY RUN COMPLETE] No modifications were committed to the database.");
            } else {
                DB::commit();
                $this->info("\n[BACKFILL COMPLETE] All modifications successfully committed.");
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Rule 1: Assigned via Orders History', $report['rule_1_order_inference']],
                    ['Rule 2: Assigned to Sole Store', $report['rule_2_single_store_inference']],
                    ['Rule 3: Cross-Branch Cloned Tables', $report['rule_3_cross_branch_clones']],
                    ['Rule 4: Ambiguous Quarantined Tables', $report['rule_4_quarantined_orphans']],
                    ['Orders Backfilled table_number', $report['orders_table_number_backfilled']],
                    ['Tables Backfilled qr_token', $report['tables_qr_token_backfilled']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Error during backfill: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
