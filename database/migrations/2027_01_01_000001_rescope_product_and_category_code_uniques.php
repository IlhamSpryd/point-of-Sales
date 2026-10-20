<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inkremen 1 — Modul 3
 * Rescope product_code dan category_code dari global unique
 * ke tenant-scoped unique. Selaras dengan pola rescope_uniques.php
 * yang sudah ada untuk tabel lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        // products.product_code: drop global unique, tambah tenant-scoped
        try {
            DB::statement('ALTER TABLE `products` DROP INDEX `products_product_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `products` ADD UNIQUE INDEX `products_tenant_id_product_code_unique` (`tenant_id`, `product_code`)');
        } catch (Exception $e) {
        }

        // categories.category_code: drop global unique, tambah tenant-scoped
        try {
            DB::statement('ALTER TABLE `categories` DROP INDEX `categories_category_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `categories` ADD UNIQUE INDEX `categories_tenant_id_category_code_unique` (`tenant_id`, `category_code`)');
        } catch (Exception $e) {
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE `products` DROP INDEX `products_tenant_id_product_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `products` ADD UNIQUE INDEX `products_product_code_unique` (`product_code`)');
        } catch (Exception $e) {
        }

        try {
            DB::statement('ALTER TABLE `categories` DROP INDEX `categories_tenant_id_category_code_unique`');
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `categories` ADD UNIQUE INDEX `categories_category_code_unique` (`category_code`)');
        } catch (Exception $e) {
        }
    }
};
