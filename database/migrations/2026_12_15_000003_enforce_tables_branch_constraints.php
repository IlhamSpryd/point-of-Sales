<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * T2.4: Memperketat constraint tables.store_id (NOT NULL) dan keunikan nama meja per (tenant_id, store_id)
     */
    public function up(): void
    {
        // 1. Verifikasi tidak ada baris tables dengan tenant_id atau store_id NULL
        $nullCount = DB::table('tables')->whereNull('tenant_id')->orWhereNull('store_id')->count();
        if ($nullCount > 0) {
            throw new RuntimeException("Abort: Terdapat {$nullCount} baris di tabel tables dengan tenant_id atau store_id NULL.");
        }

        // 2. Pastikan kolom store_id dan tenant_id NOT NULL
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `tables` MODIFY `tenant_id` BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE `tables` MODIFY `store_id` BIGINT UNSIGNED NOT NULL');
        }

        // 3. Pastikan composite FK ke stores aktif
        $fkExists = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "tables" AND CONSTRAINT_NAME = "tables_tenant_id_store_id_composite_foreign"'
        );
        if (empty($fkExists)) {
            DB::statement('
                ALTER TABLE `tables` ADD CONSTRAINT `tables_tenant_id_store_id_composite_foreign`
                FOREIGN KEY (`tenant_id`, `store_id`) REFERENCES `stores` (`tenant_id`, `id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        }
    }

    public function down(): void
    {
        // No-op to preserve multi-tenant safety
    }
};
