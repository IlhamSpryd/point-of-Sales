<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore composite foreign keys dropped during branch ownership migration.
     * Ensures strict database-level multi-tenancy enforcement.
     */
    public function up(): void
    {
        $existingConstraints = DB::select(
            'SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = "FOREIGN KEY"'
        );
        $existing = [];
        foreach ($existingConstraints as $row) {
            $existing[$row->TABLE_NAME][$row->CONSTRAINT_NAME] = true;
        }

        $addFkIfNotExists = function (string $table, string $fkName, array $columns, string $refTable, array $refColumns, string $onDelete = 'RESTRICT', string $onUpdate = 'CASCADE') use (&$existing) {
            if (isset($existing[$table][$fkName])) {
                return;
            }
            if (! Schema::hasTable($table) || ! Schema::hasTable($refTable)) {
                return;
            }

            Schema::table($table, function (Blueprint $t) use ($fkName, $columns, $refTable, $refColumns, $onDelete, $onUpdate) {
                $t->foreign($columns, $fkName)
                    ->references($refColumns)->on($refTable)
                    ->onDelete($onDelete)->onUpdate($onUpdate);
            });
            $existing[$table][$fkName] = true;
        };

        // 1. shifts
        $addFkIfNotExists('shifts', 'shifts_user_id_composite_foreign', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id']);

        // 2. orders
        $addFkIfNotExists('orders', 'orders_customer_id_composite_foreign', ['tenant_id', 'customer_id'], 'customers', ['tenant_id', 'id']);
        $addFkIfNotExists('orders', 'orders_discount_id_composite_foreign', ['tenant_id', 'discount_id'], 'discounts', ['tenant_id', 'id']);
        $addFkIfNotExists('orders', 'orders_shift_id_composite_foreign', ['tenant_id', 'shift_id'], 'shifts', ['tenant_id', 'id']);
        $addFkIfNotExists('orders', 'orders_table_id_composite_foreign', ['tenant_id', 'table_id'], 'tables', ['tenant_id', 'id']);
        $addFkIfNotExists('orders', 'orders_user_id_composite_foreign', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id']);
        $addFkIfNotExists('orders', 'orders_voided_by_composite_foreign', ['tenant_id', 'voided_by'], 'users', ['tenant_id', 'id']);

        // 3. order_items
        $addFkIfNotExists('order_items', 'order_items_product_id_composite_foreign', ['tenant_id', 'product_id'], 'products', ['tenant_id', 'id']);
        $addFkIfNotExists('order_items', 'order_items_order_id_composite_foreign', ['tenant_id', 'order_id'], 'orders', ['tenant_id', 'id']);
        $addFkIfNotExists('order_items', 'order_items_processed_by_composite_foreign', ['tenant_id', 'processed_by'], 'users', ['tenant_id', 'id']);

        // 4. order_item_modifiers
        $addFkIfNotExists('order_item_modifiers', 'order_item_modifiers_modifier_id_composite_foreign', ['tenant_id', 'modifier_id'], 'modifiers', ['tenant_id', 'id']);
        $addFkIfNotExists('order_item_modifiers', 'order_item_modifiers_order_item_id_composite_foreign', ['tenant_id', 'order_item_id'], 'order_items', ['tenant_id', 'id'], 'CASCADE');

        // 5. payments
        $addFkIfNotExists('payments', 'payments_order_id_composite_foreign', ['tenant_id', 'order_id'], 'orders', ['tenant_id', 'id']);
        $addFkIfNotExists('payments', 'payments_processed_by_composite_foreign', ['tenant_id', 'processed_by'], 'users', ['tenant_id', 'id']);

        // 6. cash_drawer_movements
        $addFkIfNotExists('cash_drawer_movements', 'cash_drawer_movements_approved_by_composite_foreign', ['tenant_id', 'approved_by'], 'users', ['tenant_id', 'id']);
        $addFkIfNotExists('cash_drawer_movements', 'cash_drawer_movements_created_by_composite_foreign', ['tenant_id', 'created_by'], 'users', ['tenant_id', 'id']);
        $addFkIfNotExists('cash_drawer_movements', 'cash_drawer_movements_shift_id_composite_foreign', ['tenant_id', 'shift_id'], 'shifts', ['tenant_id', 'id']);

        // 7. stock_movements
        $addFkIfNotExists('stock_movements', 'stock_movements_order_id_composite_foreign', ['tenant_id', 'order_id'], 'orders', ['tenant_id', 'id']);
        $addFkIfNotExists('stock_movements', 'stock_movements_order_item_id_composite_foreign', ['tenant_id', 'order_item_id'], 'order_items', ['tenant_id', 'id']);
        $addFkIfNotExists('stock_movements', 'stock_movements_product_id_composite_foreign', ['tenant_id', 'product_id'], 'products', ['tenant_id', 'id']);

        // 8. ingredient_stock_movements
        $addFkIfNotExists('ingredient_stock_movements', 'ingredient_stock_movements_created_by_composite_foreign', ['tenant_id', 'created_by'], 'users', ['tenant_id', 'id']);
        $addFkIfNotExists('ingredient_stock_movements', 'ingredient_stock_movements_ingredient_id_composite_foreign', ['tenant_id', 'ingredient_id'], 'ingredients', ['tenant_id', 'id']);
        $addFkIfNotExists('ingredient_stock_movements', 'ingredient_stock_movements_order_id_composite_foreign', ['tenant_id', 'order_id'], 'orders', ['tenant_id', 'id']);
        $addFkIfNotExists('ingredient_stock_movements', 'ingredient_stock_movements_order_item_id_composite_foreign', ['tenant_id', 'order_item_id'], 'order_items', ['tenant_id', 'id']);

        // 9. activity_logs
        $addFkIfNotExists('activity_logs', 'activity_logs_user_id_composite_foreign', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id']);

        // 10. export_tasks
        $addFkIfNotExists('export_tasks', 'export_tasks_requested_by_composite_foreign', ['tenant_id', 'requested_by'], 'users', ['tenant_id', 'id']);

        // 11. channel_order_logs
        $addFkIfNotExists('channel_order_logs', 'channel_order_logs_order_id_composite_foreign', ['tenant_id', 'order_id'], 'orders', ['tenant_id', 'id']);

        // 12. Branch ownership FKs to stores (if missing on older databases)
        $branchTables = [
            'shifts', 'orders', 'order_items', 'order_item_modifiers', 'payments',
            'stock_movements', 'ingredient_stock_movements', 'cash_drawer_movements',
            'tables', 'activity_logs', 'export_tasks',
        ];
        foreach ($branchTables as $bTable) {
            $addFkIfNotExists($bTable, "{$bTable}_tenant_id_store_id_composite_foreign", ['tenant_id', 'store_id'], 'stores', ['tenant_id', 'id']);
        }

        // 13. Provisioning & Onboarding FKs (if missing on older databases)
        if (Schema::hasTable('provisioning_requests') && Schema::hasTable('tenants')) {
            $addFkIfNotExists('provisioning_requests', 'provisioning_requests_result_tenant_fk', ['result_tenant_id'], 'tenants', ['id'], 'SET NULL');
            $addFkIfNotExists('provisioning_requests', 'provisioning_requests_result_user_fk', ['result_user_id'], 'users', ['id'], 'SET NULL');
        }
        if (Schema::hasTable('owner_onboarding_tokens') && Schema::hasTable('users')) {
            $addFkIfNotExists('owner_onboarding_tokens', 'onboarding_tokens_tenant_user_fk', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'], 'CASCADE');
        }
        if (Schema::hasTable('email_outbox') && Schema::hasTable('tenants')) {
            $addFkIfNotExists('email_outbox', 'email_outbox_tenant_fk', ['tenant_id'], 'tenants', ['id'], 'SET NULL');
            $addFkIfNotExists('email_outbox', 'email_outbox_user_fk', ['user_id'], 'users', ['id'], 'SET NULL');
        }
    }

    public function down(): void
    {
        // Drop safely if exists
        $dropFk = function (string $table, string $fkName) {
            if (Schema::hasTable($table)) {
                try {
                    Schema::table($table, function (Blueprint $t) use ($fkName) {
                        $t->dropForeign($fkName);
                    });
                } catch (\Throwable) {
                }
            }
        };

        $dropFk('email_outbox', 'email_outbox_user_fk');
        $dropFk('email_outbox', 'email_outbox_tenant_fk');
        $dropFk('owner_onboarding_tokens', 'onboarding_tokens_tenant_user_fk');
        $dropFk('provisioning_requests', 'provisioning_requests_result_user_fk');
        $dropFk('provisioning_requests', 'provisioning_requests_result_tenant_fk');

        $branchTables = [
            'shifts', 'orders', 'order_items', 'order_item_modifiers', 'payments',
            'stock_movements', 'ingredient_stock_movements', 'cash_drawer_movements',
            'tables', 'activity_logs', 'export_tasks',
        ];
        foreach ($branchTables as $bTable) {
            $dropFk($bTable, "{$bTable}_tenant_id_store_id_composite_foreign");
        }

        $dropFk('channel_order_logs', 'channel_order_logs_order_id_composite_foreign');
        $dropFk('export_tasks', 'export_tasks_requested_by_composite_foreign');
        $dropFk('activity_logs', 'activity_logs_user_id_composite_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_order_item_id_composite_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_order_id_composite_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_ingredient_id_composite_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_created_by_composite_foreign');
        $dropFk('stock_movements', 'stock_movements_product_id_composite_foreign');
        $dropFk('stock_movements', 'stock_movements_order_item_id_composite_foreign');
        $dropFk('stock_movements', 'stock_movements_order_id_composite_foreign');
        $dropFk('cash_drawer_movements', 'cash_drawer_movements_shift_id_composite_foreign');
        $dropFk('cash_drawer_movements', 'cash_drawer_movements_created_by_composite_foreign');
        $dropFk('cash_drawer_movements', 'cash_drawer_movements_approved_by_composite_foreign');
        $dropFk('payments', 'payments_processed_by_composite_foreign');
        $dropFk('payments', 'payments_order_id_composite_foreign');
        $dropFk('order_item_modifiers', 'order_item_modifiers_order_item_id_composite_foreign');
        $dropFk('order_item_modifiers', 'order_item_modifiers_modifier_id_composite_foreign');
        $dropFk('order_items', 'order_items_processed_by_composite_foreign');
        $dropFk('order_items', 'order_items_order_id_composite_foreign');
        $dropFk('order_items', 'order_items_product_id_composite_foreign');
        $dropFk('orders', 'orders_voided_by_composite_foreign');
        $dropFk('orders', 'orders_user_id_composite_foreign');
        $dropFk('orders', 'orders_table_id_composite_foreign');
        $dropFk('orders', 'orders_shift_id_composite_foreign');
        $dropFk('orders', 'orders_discount_id_composite_foreign');
        $dropFk('orders', 'orders_customer_id_composite_foreign');
        $dropFk('shifts', 'shifts_user_id_composite_foreign');
    }
};
