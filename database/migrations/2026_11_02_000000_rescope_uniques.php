<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        try {
            DB::statement('ALTER TABLE `categories` ADD UNIQUE INDEX `categories_tenant_id_category_name_active_unique` (`tenant_id`, `category_name`, `name_uniqueness_key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` ADD UNIQUE INDEX `roles_tenant_id_name_unique` (`tenant_id`, `name`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` ADD UNIQUE INDEX `roles_tenant_id_role_code_unique` (`tenant_id`, `role_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` ADD UNIQUE INDEX `tables_tenant_id_table_name_active_unique` (`tenant_id`, `table_name`, `table_name_uniqueness_key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` ADD UNIQUE INDEX `tables_tenant_id_table_code_unique` (`tenant_id`, `table_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` ADD UNIQUE INDEX `customers_tenant_id_phone_active_unique` (`tenant_id`, `phone`, `phone_uniqueness_key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` ADD UNIQUE INDEX `customers_tenant_id_customer_code_unique` (`tenant_id`, `customer_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` ADD UNIQUE INDEX `customers_tenant_id_email_unique` (`tenant_id`, `email`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `discounts` ADD UNIQUE INDEX `discounts_tenant_id_code_unique` (`tenant_id`, `code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `ingredients` ADD UNIQUE INDEX `ingredients_tenant_id_ingredient_code_unique` (`tenant_id`, `ingredient_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `loyalty_tiers` ADD UNIQUE INDEX `loyalty_tiers_tenant_id_tier_code_unique` (`tenant_id`, `tier_code`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `settings` ADD UNIQUE INDEX `settings_tenant_id_key_unique` (`tenant_id`, `key`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_product_mappings` ADD UNIQUE INDEX `channel_product_mappings_tenant_id_provider_sku_unique` (`tenant_id`, `provider`, `external_product_id`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_order_logs` ADD UNIQUE INDEX `channel_order_logs_tenant_id_provider_ext_order_id_unique` (`tenant_id`, `provider`, `external_order_id`)');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` ADD UNIQUE INDEX `orders_tenant_idempotency_key_unique` (`tenant_id`, `idempotency_key`)');
        } catch (Exception $e) {
        }
    }

    public function down()
    {
        try {
            DB::statement('ALTER TABLE `categories` DROP INDEX `categories_tenant_id_category_name_active_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` DROP INDEX `roles_tenant_id_name_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `roles` DROP INDEX `roles_tenant_id_role_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` DROP INDEX `tables_tenant_id_table_name_active_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `tables` DROP INDEX `tables_tenant_id_table_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` DROP INDEX `customers_tenant_id_phone_active_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` DROP INDEX `customers_tenant_id_customer_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `customers` DROP INDEX `customers_tenant_id_email_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `discounts` DROP INDEX `discounts_tenant_id_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `ingredients` DROP INDEX `ingredients_tenant_id_ingredient_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `loyalty_tiers` DROP INDEX `loyalty_tiers_tenant_id_tier_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `settings` DROP INDEX `settings_tenant_id_key_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_product_mappings` DROP INDEX `channel_product_mappings_tenant_id_provider_sku_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `channel_order_logs` DROP INDEX `channel_order_logs_tenant_id_provider_ext_order_id_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` DROP INDEX `orders_tenant_idempotency_key_unique`');
        } catch (Exception $e) {
        }
    }
};
