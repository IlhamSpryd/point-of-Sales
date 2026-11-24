<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 9D — Close three pre-existing CompositeForeignKeyIsolationTest gaps:
 *
 * 1. commission_records.payroll_batch_id: single-column FK → composite (tenant_id, payroll_batch_id)
 * 2. table_sessions: missing UNIQUE(tenant_id, id)
 * 3. ofd_channel_configs: missing UNIQUE(tenant_id, id)
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        // --- 1. Fix commission_records.payroll_batch_id single-column FK ---
        if (
            Schema::hasTable('commission_records')
            && Schema::hasTable('payroll_batches')
            && Schema::hasColumn('commission_records', 'payroll_batch_id')
        ) {
            // Ensure payroll_batches has the UNIQUE(tenant_id, id) target for composite FK
            $this->ensureUniqueIndex('payroll_batches');

            // Drop existing single-column FK(s) on payroll_batch_id
            $existingFks = $this->getExistingForeignKeysForTable('commission_records');
            foreach ($existingFks as $fkName => $cols) {
                if ($cols === ['payroll_batch_id']) {
                    DB::statement("ALTER TABLE `commission_records` DROP FOREIGN KEY `{$fkName}`");
                }
            }

            // Drop standalone single-column index on payroll_batch_id if it exists
            if ($this->indexExists('commission_records', 'comm_records_payroll_batch_idx')) {
                DB::statement('ALTER TABLE `commission_records` DROP INDEX `comm_records_payroll_batch_idx`');
            }

            // Ensure composite index exists first so InnoDB reuses it cleanly for the FK
            if (! $this->indexExists('commission_records', 'comm_records_tenant_payroll_batch_idx')) {
                DB::statement('
                    ALTER TABLE `commission_records`
                    ADD INDEX `comm_records_tenant_payroll_batch_idx` (`tenant_id`, `payroll_batch_id`)
                ');
            }

            // Add composite FK: (tenant_id, payroll_batch_id) → payroll_batches(tenant_id, id)
            // Must use ON DELETE RESTRICT because tenant_id is NOT NULL.
            if (! isset($existingFks['fk_commission_records_payroll_batch_id_tenant_comp'])) {
                DB::statement('
                    ALTER TABLE `commission_records`
                    ADD CONSTRAINT `fk_commission_records_payroll_batch_id_tenant_comp`
                    FOREIGN KEY (`tenant_id`, `payroll_batch_id`)
                    REFERENCES `payroll_batches` (`tenant_id`, `id`)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE
                ');
            }
        }

        // --- 2. Add UNIQUE(tenant_id, id) on table_sessions ---
        $this->ensureUniqueIndex('table_sessions');

        // --- 3. Add UNIQUE(tenant_id, id) on ofd_channel_configs ---
        $this->ensureUniqueIndex('ofd_channel_configs');
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        // Reverse commission_records composite FK back to single-column
        if (
            Schema::hasTable('commission_records')
            && Schema::hasTable('payroll_batches')
            && Schema::hasColumn('commission_records', 'payroll_batch_id')
        ) {
            $existingFks = $this->getExistingForeignKeysForTable('commission_records');
            if (isset($existingFks['fk_commission_records_payroll_batch_id_tenant_comp'])) {
                DB::statement('ALTER TABLE `commission_records` DROP FOREIGN KEY `fk_commission_records_payroll_batch_id_tenant_comp`');
            }

            if ($this->indexExists('commission_records', 'comm_records_tenant_payroll_batch_idx')) {
                DB::statement('ALTER TABLE `commission_records` DROP INDEX `comm_records_tenant_payroll_batch_idx`');
            }

            if (! $this->indexExists('commission_records', 'comm_records_payroll_batch_idx')) {
                DB::statement('
                    ALTER TABLE `commission_records`
                    ADD INDEX `comm_records_payroll_batch_idx` (`payroll_batch_id`)
                ');
            }

            if (! isset($existingFks['commission_records_payroll_batch_id_foreign'])) {
                DB::statement('
                    ALTER TABLE `commission_records`
                    ADD CONSTRAINT `commission_records_payroll_batch_id_foreign`
                    FOREIGN KEY (`payroll_batch_id`)
                    REFERENCES `payroll_batches` (`id`)
                    ON DELETE SET NULL
                ');
            }
        }

        // Drop UNIQUE(tenant_id, id) from table_sessions
        $this->dropUniqueIndex('table_sessions');

        // Drop UNIQUE(tenant_id, id) from ofd_channel_configs
        $this->dropUniqueIndex('ofd_channel_configs');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return ! empty(DB::select('
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ', [$table, $indexName]));
    }

    private function ensureUniqueIndex(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $ukName = substr("{$table}_tenant_id_id_unique", 0, 64);

        $exists = DB::select('
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ', [$table, $ukName]);

        if (empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$ukName}` (`tenant_id`, `id`)");
        }
    }

    private function dropUniqueIndex(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $ukName = substr("{$table}_tenant_id_id_unique", 0, 64);

        $exists = DB::select('
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ', [$table, $ukName]);

        if (! empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$ukName}`");
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function getExistingForeignKeysForTable(string $table): array
    {
        $rows = DB::select('
            SELECT k.CONSTRAINT_NAME AS fk_name, k.COLUMN_NAME AS col_name
            FROM information_schema.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.TABLE_NAME = ?
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION
        ', [$table]);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->fk_name][] = (string) $row->col_name;
        }

        return $map;
    }
};
