<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_category_id_foreign');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->dropForeign('product_ingredients_ingredient_id_foreign');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->dropForeign('product_ingredients_product_id_foreign');
        });
        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropForeign('modifiers_modifier_group_id_foreign');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->dropForeign('modifier_group_product_modifier_group_id_foreign');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->dropForeign('modifier_group_product_product_id_foreign');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->dropForeign('modifier_ingredients_ingredient_id_foreign');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->dropForeign('modifier_ingredients_modifier_id_foreign');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->dropForeign('customer_loyalty_accounts_current_tier_id_foreign');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->dropForeign('customer_loyalty_accounts_customer_id_foreign');
        });
        Schema::table('channel_product_mappings', function (Blueprint $table) {
            $table->dropForeign('channel_product_mappings_product_id_foreign');
        });
        Schema::table('ingredient_restock_forecasts', function (Blueprint $table) {
            $table->dropForeign('ingredient_restock_forecasts_ingredient_id_foreign');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id', 'products_category_id_foreign')->references('id')->on('categories')->onDelete('RESTRICT');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->foreign('ingredient_id', 'product_ingredients_ingredient_id_foreign')->references('id')->on('ingredients')->onDelete('RESTRICT');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->foreign('product_id', 'product_ingredients_product_id_foreign')->references('id')->on('products')->onDelete('CASCADE');
        });
        Schema::table('modifiers', function (Blueprint $table) {
            $table->foreign('modifier_group_id', 'modifiers_modifier_group_id_foreign')->references('id')->on('modifier_groups')->onDelete('CASCADE');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->foreign('modifier_group_id', 'modifier_group_product_modifier_group_id_foreign')->references('id')->on('modifier_groups')->onDelete('CASCADE');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->foreign('product_id', 'modifier_group_product_product_id_foreign')->references('id')->on('products')->onDelete('CASCADE');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->foreign('ingredient_id', 'modifier_ingredients_ingredient_id_foreign')->references('id')->on('ingredients')->onDelete('CASCADE');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->foreign('modifier_id', 'modifier_ingredients_modifier_id_foreign')->references('id')->on('modifiers')->onDelete('CASCADE');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->foreign('current_tier_id', 'customer_loyalty_accounts_current_tier_id_foreign')->references('id')->on('loyalty_tiers')->onDelete('SET NULL');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->foreign('customer_id', 'customer_loyalty_accounts_customer_id_foreign')->references('id')->on('customers')->onDelete('CASCADE');
        });
        Schema::table('channel_product_mappings', function (Blueprint $table) {
            $table->foreign('product_id', 'channel_product_mappings_product_id_foreign')->references('id')->on('products')->onDelete('CASCADE');
        });
        Schema::table('ingredient_restock_forecasts', function (Blueprint $table) {
            $table->foreign('ingredient_id', 'ingredient_restock_forecasts_ingredient_id_foreign')->references('id')->on('ingredients')->onDelete('CASCADE');
        });
    }
};
