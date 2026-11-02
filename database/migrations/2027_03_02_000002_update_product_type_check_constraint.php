<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        try {
            DB::statement('ALTER TABLE `products` DROP CONSTRAINT `products_product_type_check`');
        } catch (\Throwable) {
            try {
                DB::statement('ALTER TABLE `products` DROP CHECK `products_product_type_check`');
            } catch (\Throwable) {
            }
        }

        try {
            DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_product_type_check` CHECK (LOWER(`product_type`) IN ('standard','variant_parent','bundle','service','non_inventory'))");
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        try {
            DB::statement('ALTER TABLE `products` DROP CONSTRAINT `products_product_type_check`');
        } catch (\Throwable) {
        }

        try {
            DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_product_type_check` CHECK (`product_type` IN ('standard','service','non_inventory','bundle'))");
        } catch (\Throwable) {
        }
    }
};
