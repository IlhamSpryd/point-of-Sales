<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        try {
            DB::statement('ALTER TABLE `categories` DROP INDEX `categories_category_name_active_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` DROP INDEX `roles_name_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` DROP INDEX `roles_role_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` DROP INDEX `tables_table_name_active_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` DROP INDEX `tables_table_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` DROP INDEX `customers_phone_active_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` DROP INDEX `customers_customer_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` DROP INDEX `customers_email_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `discounts` DROP INDEX `discounts_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `ingredients` DROP INDEX `ingredients_ingredient_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `loyalty_tiers` DROP INDEX `loyalty_tiers_tier_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `settings` DROP INDEX `settings_key_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_product_mappings` DROP INDEX `channel_product_mappings_provider_sku_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_order_logs` DROP INDEX `channel_order_logs_provider_external_order_id_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` DROP INDEX `orders_idempotency_key_unique`');
        } catch (Exception $e) {
        }
    }

    public function down()
    {
        try {
            DB::statement('ALTER TABLE `categories` ADD UNIQUE INDEX `categories_category_name_active_unique` (`category_name`, `name_uniqueness_key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` ADD UNIQUE INDEX `roles_name_unique` (`name`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` ADD UNIQUE INDEX `roles_role_code_unique` (`role_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` ADD UNIQUE INDEX `tables_table_name_active_unique` (`table_name`, `table_name_uniqueness_key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` ADD UNIQUE INDEX `tables_table_code_unique` (`table_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` ADD UNIQUE INDEX `customers_phone_active_unique` (`phone`, `phone_uniqueness_key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` ADD UNIQUE INDEX `customers_customer_code_unique` (`customer_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` ADD UNIQUE INDEX `customers_email_unique` (`email`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `discounts` ADD UNIQUE INDEX `discounts_code_unique` (`code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `ingredients` ADD UNIQUE INDEX `ingredients_ingredient_code_unique` (`ingredient_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `loyalty_tiers` ADD UNIQUE INDEX `loyalty_tiers_tier_code_unique` (`tier_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `settings` ADD UNIQUE INDEX `settings_key_unique` (`key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_product_mappings` ADD UNIQUE INDEX `channel_product_mappings_provider_sku_unique` (`provider`, `external_product_id`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_order_logs` ADD UNIQUE INDEX `channel_order_logs_provider_external_order_id_unique` (`provider`, `external_order_id`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` ADD UNIQUE INDEX `orders_idempotency_key_unique` (`idempotency_key`)');
        } catch (Exception $e) {
        }
    }
};
