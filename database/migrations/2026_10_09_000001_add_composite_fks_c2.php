<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'categories_tenant_id_id_unique');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'products_tenant_id_id_unique');
        });
        Schema::table('ingredients', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'ingredients_tenant_id_id_unique');
        });
        Schema::table('modifiers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'modifiers_tenant_id_id_unique');
        });
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'modifier_groups_tenant_id_id_unique');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'customers_tenant_id_id_unique');
        });
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'loyalty_tiers_tenant_id_id_unique');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'category_id'], 'products_category_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('categories')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'ingredient_id'], 'product_ingredients_ingredient_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'product_id'], 'product_ingredients_product_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('modifiers', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'modifier_group_id'], 'modifiers_modifier_group_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('modifier_groups')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'modifier_group_id'], 'modifier_group_product_modifier_group_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('modifier_groups')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'product_id'], 'modifier_group_product_product_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'ingredient_id'], 'modifier_ingredients_ingredient_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'modifier_id'], 'modifier_ingredients_modifier_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('modifiers')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'current_tier_id'], 'customer_loyalty_accounts_current_tier_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('loyalty_tiers')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'customer_id'], 'customer_loyalty_accounts_customer_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('channel_product_mappings', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'product_id'], 'channel_product_mappings_product_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('ingredient_restock_forecasts', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'ingredient_id'], 'ingredient_restock_forecasts_ingredient_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_category_id_composite_foreign');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->dropForeign('product_ingredients_ingredient_id_composite_foreign');
        });
        Schema::table('product_ingredients', function (Blueprint $table) {
            $table->dropForeign('product_ingredients_product_id_composite_foreign');
        });
        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropForeign('modifiers_modifier_group_id_composite_foreign');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->dropForeign('modifier_group_product_modifier_group_id_composite_foreign');
        });
        Schema::table('modifier_group_product', function (Blueprint $table) {
            $table->dropForeign('modifier_group_product_product_id_composite_foreign');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->dropForeign('modifier_ingredients_ingredient_id_composite_foreign');
        });
        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->dropForeign('modifier_ingredients_modifier_id_composite_foreign');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->dropForeign('customer_loyalty_accounts_current_tier_id_composite_foreign');
        });
        Schema::table('customer_loyalty_accounts', function (Blueprint $table) {
            $table->dropForeign('customer_loyalty_accounts_customer_id_composite_foreign');
        });
        Schema::table('channel_product_mappings', function (Blueprint $table) {
            $table->dropForeign('channel_product_mappings_product_id_composite_foreign');
        });
        Schema::table('ingredient_restock_forecasts', function (Blueprint $table) {
            $table->dropForeign('ingredient_restock_forecasts_ingredient_id_composite_foreign');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_tenant_id_id_unique');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_tenant_id_id_unique');
        });
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique('ingredients_tenant_id_id_unique');
        });
        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropUnique('modifiers_tenant_id_id_unique');
        });
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropUnique('modifier_groups_tenant_id_id_unique');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_tenant_id_id_unique');
        });
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->dropUnique('loyalty_tiers_tenant_id_id_unique');
        });
    }
};
