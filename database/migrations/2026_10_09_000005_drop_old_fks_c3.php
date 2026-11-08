<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $dropFk = function (string $table, string $fk) {
            try {
                Schema::table($table, function (Blueprint $t) use ($fk) {
                    $t->dropForeign($fk);
                });
            } catch (\Throwable) {
            }
        };

        $dropFk('shifts', 'shifts_user_id_foreign');
        $dropFk('orders', 'orders_customer_id_foreign');
        $dropFk('orders', 'orders_discount_id_foreign');
        $dropFk('orders', 'orders_shift_id_foreign');
        $dropFk('orders', 'orders_table_id_foreign');
        $dropFk('orders', 'orders_user_id_foreign');
        $dropFk('orders', 'orders_voided_by_foreign');
        $dropFk('order_items', 'order_details_product_id_foreign');
        $dropFk('order_items', 'order_items_order_id_foreign');
        $dropFk('order_items', 'order_items_processed_by_foreign');
        $dropFk('order_item_modifiers', 'order_item_modifiers_modifier_id_foreign');
        $dropFk('order_item_modifiers', 'order_item_modifiers_order_item_id_foreign');
        $dropFk('payments', 'payments_order_id_foreign');
        $dropFk('payments', 'payments_processed_by_foreign');
        $dropFk('cash_drawer_movements', 'cash_drawer_movements_approved_by_foreign');
        $dropFk('cash_drawer_movements', 'cash_drawer_movements_created_by_foreign');
        $dropFk('cash_drawer_movements', 'cash_drawer_movements_shift_id_foreign');
        $dropFk('stock_movements', 'stock_movements_order_id_foreign');
        $dropFk('stock_movements', 'stock_movements_order_item_id_foreign');
        $dropFk('stock_movements', 'stock_movements_product_id_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_created_by_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_ingredient_id_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_order_id_foreign');
        $dropFk('ingredient_stock_movements', 'ingredient_stock_movements_order_item_id_foreign');
        $dropFk('loyalty_ledger', 'loyalty_ledger_created_by_foreign');
        $dropFk('loyalty_ledger', 'loyalty_ledger_customer_id_foreign');
        $dropFk('loyalty_ledger', 'loyalty_ledger_order_id_foreign');
        $dropFk('activity_logs', 'activity_logs_user_id_foreign');
        $dropFk('export_tasks', 'export_tasks_requested_by_foreign');
        $dropFk('channel_order_logs', 'channel_order_logs_order_id_foreign');
    }

    public function down()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign('user_id', 'shifts_user_id_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('customer_id', 'orders_customer_id_foreign')->references('id')->on('customers')->onDelete('SET NULL');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('discount_id', 'orders_discount_id_foreign')->references('id')->on('discounts')->onDelete('SET NULL');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('shift_id', 'orders_shift_id_foreign')->references('id')->on('shifts')->onDelete('RESTRICT');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('table_id', 'orders_table_id_foreign')->references('id')->on('tables')->onDelete('RESTRICT');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_id', 'orders_user_id_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('voided_by', 'orders_voided_by_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id', 'order_details_product_id_foreign')->references('id')->on('products')->onDelete('RESTRICT');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('order_id', 'order_items_order_id_foreign')->references('id')->on('orders')->onDelete('RESTRICT');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('processed_by', 'order_items_processed_by_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->foreign('modifier_id', 'order_item_modifiers_modifier_id_foreign')->references('id')->on('modifiers')->onDelete('RESTRICT');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->foreign('order_item_id', 'order_item_modifiers_order_item_id_foreign')->references('id')->on('order_items')->onDelete('CASCADE');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('order_id', 'payments_order_id_foreign')->references('id')->on('orders')->onDelete('RESTRICT');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('processed_by', 'payments_processed_by_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->foreign('approved_by', 'cash_drawer_movements_approved_by_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->foreign('created_by', 'cash_drawer_movements_created_by_foreign')->references('id')->on('users')->onDelete('RESTRICT');
        });
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->foreign('shift_id', 'cash_drawer_movements_shift_id_foreign')->references('id')->on('shifts')->onDelete('RESTRICT');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('order_id', 'stock_movements_order_id_foreign')->references('id')->on('orders')->onDelete('RESTRICT');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('order_item_id', 'stock_movements_order_item_id_foreign')->references('id')->on('order_items')->onDelete('RESTRICT');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('product_id', 'stock_movements_product_id_foreign')->references('id')->on('products')->onDelete('RESTRICT');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign('created_by', 'ingredient_stock_movements_created_by_foreign')->references('id')->on('users')->onDelete('SET NULL');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign('ingredient_id', 'ingredient_stock_movements_ingredient_id_foreign')->references('id')->on('ingredients')->onDelete('RESTRICT');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign('order_id', 'ingredient_stock_movements_order_id_foreign')->references('id')->on('orders')->onDelete('RESTRICT');
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreign('order_item_id', 'ingredient_stock_movements_order_item_id_foreign')->references('id')->on('order_items')->onDelete('RESTRICT');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->foreign('created_by', 'loyalty_ledger_created_by_foreign')->references('id')->on('users')->onDelete('SET NULL');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->foreign('customer_id', 'loyalty_ledger_customer_id_foreign')->references('id')->on('customers')->onDelete('RESTRICT');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->foreign('order_id', 'loyalty_ledger_order_id_foreign')->references('id')->on('orders')->onDelete('RESTRICT');
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('user_id', 'activity_logs_user_id_foreign')->references('id')->on('users')->onDelete('SET NULL');
        });
        Schema::table('export_tasks', function (Blueprint $table) {
            $table->foreign('requested_by', 'export_tasks_requested_by_foreign')->references('id')->on('users')->onDelete('SET NULL');
        });
        Schema::table('channel_order_logs', function (Blueprint $table) {
            $table->foreign('order_id', 'channel_order_logs_order_id_foreign')->references('id')->on('orders')->onDelete('SET NULL');
        });
    }
};
