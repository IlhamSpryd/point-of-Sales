<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE stock_movements MODIFY tenant_id bigint(20) unsigned DEFAULT NULL');
        DB::statement('ALTER TABLE ingredient_stock_movements MODIFY tenant_id bigint(20) unsigned DEFAULT NULL');
        Schema::table('shifts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'shifts_tenant_id_id_unique');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'orders_tenant_id_id_unique');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'order_items_tenant_id_id_unique');
        });
        Schema::table('tables', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'tables_tenant_id_id_unique');
        });
        Schema::table('discounts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'discounts_tenant_id_id_unique');
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'user_id'], 'shifts_user_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'customer_id'], 'orders_customer_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'discount_id'], 'orders_discount_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('discounts')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'shift_id'], 'orders_shift_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('shifts')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'table_id'], 'orders_table_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('tables')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'user_id'], 'orders_user_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'voided_by'], 'orders_voided_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'product_id'], 'order_items_product_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'], 'order_items_order_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'processed_by'], 'order_items_processed_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'modifier_id'], 'order_item_modifiers_modifier_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('modifiers')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_item_id'], 'order_item_modifiers_order_item_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('order_items')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'], 'payments_order_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'processed_by'], 'payments_processed_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'approved_by'], 'cash_drawer_movements_approved_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'created_by'], 'cash_drawer_movements_created_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'shift_id'], 'cash_drawer_movements_shift_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('shifts')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'], 'stock_movements_order_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_item_id'], 'stock_movements_order_item_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('order_items')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'product_id'], 'stock_movements_product_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'created_by'], 'ingredient_stock_movements_created_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'ingredient_id'], 'ingredient_stock_movements_ingredient_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'], 'ingredient_stock_movements_order_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_item_id'], 'ingredient_stock_movements_order_item_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('order_items')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'created_by'], 'loyalty_ledger_created_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'customer_id'], 'loyalty_ledger_customer_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'], 'loyalty_ledger_order_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'user_id'], 'activity_logs_user_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('export_tasks', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'requested_by'], 'export_tasks_requested_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('channel_order_logs', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'], 'channel_order_logs_order_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
    }

    public function down()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign('shifts_user_id_composite_foreign');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_customer_id_composite_foreign');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_discount_id_composite_foreign');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_shift_id_composite_foreign');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_table_id_composite_foreign');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_user_id_composite_foreign');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_voided_by_composite_foreign');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_product_id_composite_foreign');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_order_id_composite_foreign');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_processed_by_composite_foreign');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropForeign('order_item_modifiers_modifier_id_composite_foreign');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropForeign('order_item_modifiers_order_item_id_composite_foreign');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_order_id_composite_foreign');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_processed_by_composite_foreign');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->dropForeign('cash_drawer_movements_approved_by_composite_foreign');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->dropForeign('cash_drawer_movements_created_by_composite_foreign');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->dropForeign('cash_drawer_movements_shift_id_composite_foreign');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_movements_order_id_composite_foreign');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_movements_order_item_id_composite_foreign');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_movements_product_id_composite_foreign');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign('ingredient_stock_movements_created_by_composite_foreign');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign('ingredient_stock_movements_ingredient_id_composite_foreign');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign('ingredient_stock_movements_order_id_composite_foreign');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign('ingredient_stock_movements_order_item_id_composite_foreign');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->dropForeign('loyalty_ledger_created_by_composite_foreign');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->dropForeign('loyalty_ledger_customer_id_composite_foreign');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->dropForeign('loyalty_ledger_order_id_composite_foreign');
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_user_id_composite_foreign');
        });
        Schema::table('export_tasks', function (Blueprint $table) {
            $table->dropForeign('export_tasks_requested_by_composite_foreign');
        });
        Schema::table('channel_order_logs', function (Blueprint $table) {
            $table->dropForeign('channel_order_logs_order_id_composite_foreign');
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique('shifts_tenant_id_id_unique');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_tenant_id_id_unique');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique('order_items_tenant_id_id_unique');
        });
        Schema::table('tables', function (Blueprint $table) {
            $table->dropUnique('tables_tenant_id_id_unique');
        });
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropUnique('discounts_tenant_id_id_unique');
        });
    }
};
