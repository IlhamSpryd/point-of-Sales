<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 — unique nama meja menjadi per (tenant_id, store_id), serta
 * memastikan user_stores memiliki tenant_id NOT NULL dan FK komposit.
 *
 * Pola soft-delete-aware (table_name_uniqueness_key) dipertahankan.
 * Index lama di-drop pada migrasi TERPISAH:
 * 2026_12_01_000002_drop_old_table_name_unique.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) pre-check: duplikat non-NULL dalam (tenant_id, store_id)
        $dupes = DB::select('
            SELECT COUNT(*) c FROM (
                SELECT 1 FROM tables
                WHERE table_name IS NOT NULL AND table_name_uniqueness_key IS NOT NULL
                GROUP BY tenant_id, store_id, table_name, table_name_uniqueness_key
                HAVING COUNT(*) > 1 LIMIT 1
            ) d
        ');
        if (($dupes[0]->c ?? 0) > 0) {
            throw new Exception('Refusing to add tables_tenant_id_store_id_table_name_active_unique: duplicate table names exist within a store.');
        }

        // (b) index tenant+store-leading.
        $new = 'tables_tenant_id_store_id_table_name_active_unique';
        $exists = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['tables', $new]
        );
        if (empty($exists)) {
            DB::statement("ALTER TABLE `tables` ADD UNIQUE INDEX `{$new}` (`tenant_id`, `store_id`, `table_name`, `table_name_uniqueness_key`)");
        }

        // (c) verify
        $exists = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['tables', $new]
        );
        if (empty($exists)) {
            throw new Exception("Verification failed: {$new} missing.");
        }

        // user_stores: pastikan tenant_id NOT NULL (FK komposit sudah Phase 10).
        if (DB::table('user_stores')->whereNull('tenant_id')->count() > 0) {
            throw new Exception('Abort: user_stores masih memiliki tenant_id NULL.');
        }
        DB::statement('ALTER TABLE `user_stores` MODIFY `tenant_id` BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE `tables` DROP INDEX `tables_tenant_id_store_id_table_name_active_unique`');
        } catch (Throwable $e) {
        }
    }
};
