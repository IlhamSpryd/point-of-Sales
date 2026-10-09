<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 — drop index lama tables_tenant_id_table_name_active_unique
 * (dibuat Phase 9) setelah index per-store dipasang & diverifikasi.
 * Migrasi terpisah => tidak pernah ada jendela tanpa jaminan uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('ALTER TABLE `tables` DROP INDEX `tables_tenant_id_table_name_active_unique`');
        } catch (Throwable $e) {
            // Sudah tidak ada (idempoten).
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE `tables` ADD UNIQUE INDEX `tables_tenant_id_table_name_active_unique` (`tenant_id`, `table_name`, `table_name_uniqueness_key`)');
        } catch (Throwable $e) {
        }
    }
};
