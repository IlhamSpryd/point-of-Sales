<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement('SET foreign_key_checks = 0');
        $tables = ['shifts', 'orders', 'order_items', 'tables', 'discounts', 'order_item_modifiers', 'payments', 'cash_drawer_movements', 'stock_movements', 'ingredient_stock_movements', 'loyalty_ledger', 'activity_logs', 'export_tasks', 'channel_order_logs'];
        foreach ($tables as $table) {
            $nullCount = DB::table($table)->whereNull('tenant_id')->count();
            if ($nullCount > 0) {
                throw new Exception("Table {$table} has {$nullCount} NULL tenant_id records.");
            }
            $create = DB::selectOne("SHOW CREATE TABLE `$table`")->{'Create Table'};
            preg_match_all('/CONSTRAINT `([^`]+)` FOREIGN KEY \\(`([^`]+)`(?:, `([^`]+)`)?\) REFERENCES `([^`]+)` \\(`([^`]+)`(?:, `([^`]+)`)?\)([^,\
]+)/', $create, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                DB::statement("ALTER TABLE `$table` DROP FOREIGN KEY `{$m[1]}`");
            }
            DB::statement("ALTER TABLE `$table` MODIFY `tenant_id` BIGINT UNSIGNED NOT NULL");
            try {
                DB::statement("ALTER TABLE `$table` ALTER COLUMN `tenant_id` DROP DEFAULT");
            } catch (Exception $e) {
            }
            foreach ($matches as $m) {
                $col2 = ! empty($m[3]) ? ", `{$m[3]}`" : '';
                $ref2 = ! empty($m[6]) ? ", `{$m[6]}`" : '';
                DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$m[1]}` FOREIGN KEY (`{$m[2]}`$col2) REFERENCES `{$m[4]}` (`{$m[5]}`$ref2) {$m[7]}");
            }
            $fkExists = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = 'tenant_id' AND REFERENCED_TABLE_NAME = 'tenants'");
            if (empty($fkExists)) {
                DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE RESTRICT");
            }
            $idxExists = DB::select("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '{$table}_tenant_id_id_unique'");
            if (empty($idxExists)) {
                DB::statement("ALTER TABLE `$table` ADD UNIQUE INDEX `{$table}_tenant_id_id_unique` (`tenant_id`, `id`)");
            }
        }
        DB::statement('SET foreign_key_checks = 1');
    }

    public function down()
    {
        DB::statement('SET foreign_key_checks = 0');
        $tables = ['shifts', 'orders', 'order_items', 'tables', 'discounts', 'order_item_modifiers', 'payments', 'cash_drawer_movements', 'stock_movements', 'ingredient_stock_movements', 'loyalty_ledger', 'activity_logs', 'export_tasks', 'channel_order_logs'];
        foreach ($tables as $table) {
            try {
                DB::statement("ALTER TABLE `$table` DROP FOREIGN KEY `{$table}_tenant_id_foreign`");
            } catch (Exception $e) {
            }
        }
        DB::statement('SET foreign_key_checks = 1');
    }
};
