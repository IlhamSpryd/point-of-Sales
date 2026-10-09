<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — jadikan cabang (store) boundary isolasi nyata.
 *
 * Langkah (per cluster):
 *  1. UNIQUE(tenant_id, id) pada stores (dibutuhkan FK komposit).
 *  2. Backfill store_id NULL: bila tenant punya TEPAT SATU store -> store itu;
 *     bila tenant punya >1 store dan baris tak bisa diatribusikan -> ABORT.
 *  3. store_id NOT NULL + tanpa DEFAULT (hapus sisa DEFAULT 1).
 *  4. FK komposit (tenant_id, store_id) -> stores(tenant_id, id) RESTRICT.
 *
 * Catatan: karena Phase 10 sudah memasang FK komposit lain di tabel yang sama,
 * DDL dijalankan dengan menyusun ulang FK (drop -> MODIFY -> recreate persis).
 */
return new class extends Migration
{
    /** tabel branch-owned yang store_id-nya dijadikan NOT NULL */
    private array $branchTables = [
        'shifts', 'orders', 'order_items', 'order_item_modifiers', 'payments',
        'stock_movements', 'ingredient_stock_movements', 'cash_drawer_movements',
        'tables', 'activity_logs', 'export_tasks',
    ];

    public function up(): void
    {
        DB::statement('SET foreign_key_checks = 0');

        // 1. UNIQUE(tenant_id, id) pada stores bila belum ada.
        $exists = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['stores', 'stores_tenant_id_id_unique']
        );
        if (empty($exists)) {
            DB::statement('ALTER TABLE `stores` ADD UNIQUE INDEX `stores_tenant_id_id_unique` (`tenant_id`, `id`)');
        }

        // 2. Backfill store_id.
        foreach ($this->branchTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            $nullTenants = DB::table($table)
                ->whereNull('store_id')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id');

            foreach ($nullTenants as $tenantId) {
                $storeIds = DB::table('stores')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');

                if ($storeIds->isEmpty()) {
                    throw new Exception("Abort: tenant {$tenantId} tidak punya store sama sekali, tetapi tabel {$table} memiliki baris dengan store_id NULL.");
                }

                if ($storeIds->count() > 1) {
                    throw new Exception("Abort: tenant {$tenantId} punya >1 store sehingga baris store_id NULL di {$table} tidak dapat diatribusikan otomatis (H-03: konfirmasi manusia diperlukan).");
                }

                DB::table($table)
                    ->whereNull('store_id')
                    ->where('tenant_id', $tenantId)
                    ->update(['store_id' => (int) $storeIds->first()]);
            }
        }

        // 3 + 4. NOT NULL, drop DEFAULT, FK komposit.
        foreach ($this->branchTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            $remaining = DB::table($table)->whereNull('store_id')->count();
            if ($remaining > 0) {
                throw new Exception("Abort: {$table} masih punya {$remaining} baris store_id NULL.");
            }

            // Drop seluruh FK agar MODIFY kolom tidak bentrok tipe (Phase 10 FKs).
            foreach ($this->foreignKeysOf($table) as $fk) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk['name']}`");
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `store_id` BIGINT UNSIGNED NOT NULL");
            try {
                DB::statement("ALTER TABLE `{$table}` ALTER COLUMN `store_id` DROP DEFAULT");
            } catch (Throwable $e) {
                // Tidak semua versi mendukung ALTER COLUMN ... DROP DEFAULT.
            }

            foreach ($this->foreignKeysOf($table) as $fk) {
                $cols = implode('`, `', $fk['columns']);
                $refCols = implode('`, `', $fk['ref_columns']);
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk['name']}` FOREIGN KEY (`{$cols}`) REFERENCES `{$fk['ref_table']}` (`{$refCols}`) {$fk['rest']}");
            }

            // FK komposit ke stores.
            $fkName = "{$table}_tenant_id_store_id_composite_foreign";
            $has = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$table, $fkName]
            );
            if (empty($has)) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`tenant_id`, `store_id`) REFERENCES `stores` (`tenant_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE");
            }
        }

        DB::statement('SET foreign_key_checks = 1');
    }

    public function down(): void
    {
        DB::statement('SET foreign_key_checks = 0');
        foreach ($this->branchTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                continue;
            }
            try {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$table}_tenant_id_store_id_composite_foreign`");
            } catch (Throwable $e) {
            }
        }
        DB::statement('SET foreign_key_checks = 1');
    }

    /**
     * @return array<int, array{name: string, columns: array<int, string>, ref_table: string, ref_columns: array<int, string>, rest: string}>
     */
    private function foreignKeysOf(string $table): array
    {
        $create = DB::selectOne("SHOW CREATE TABLE `{$table}`")->{'Create Table'};
        preg_match_all(
            '/CONSTRAINT `([^`]+)` FOREIGN KEY \(`([^`]+)`(?:, `([^`]+)`)?\) REFERENCES `([^`]+)` \(`([^`]+)`(?:, `([^`]+)`)?\)([^,\n]+)/',
            $create,
            $matches,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($matches as $m) {
            $cols = [$m[2]];
            if (! empty($m[3])) {
                $cols[] = $m[3];
            }
            $refCols = [$m[5]];
            if (! empty($m[6])) {
                $refCols[] = $m[6];
            }
            $out[] = [
                'name' => $m[1],
                'columns' => $cols,
                'ref_table' => $m[4],
                'ref_columns' => $refCols,
                'rest' => trim($m[7]),
            ];
        }

        return $out;
    }
};
