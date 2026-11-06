<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * TR-008: Monthly Range Partitioning for High-Volume Historical Transaction Tables in MariaDB.
     */
    public function up(): void
    {
        // 1. Tabel Partisi Riwayat Order Items (order_items_partitioned)
        if (! Schema::hasTable('order_items_partitioned')) {
            DB::statement("
                CREATE TABLE order_items_partitioned (
                    id BIGINT UNSIGNED NOT NULL,
                    tenant_id BIGINT UNSIGNED NOT NULL,
                    store_id BIGINT UNSIGNED NOT NULL,
                    order_id BIGINT UNSIGNED NOT NULL,
                    product_id BIGINT UNSIGNED NOT NULL,
                    product_variant_id BIGINT UNSIGNED NULL,
                    order_price BIGINT NOT NULL,
                    qty INT NOT NULL,
                    order_subtotal BIGINT NOT NULL,
                    discount_id BIGINT UNSIGNED NULL,
                    discount_amount BIGINT NOT NULL DEFAULT 0,
                    notes VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id, created_at),
                    KEY idx_part_ord_items_tenant_date (tenant_id, created_at, order_id),
                    KEY idx_part_ord_items_product (tenant_id, product_id)
                ) ENGINE=InnoDB
                PARTITION BY RANGE COLUMNS (created_at) (
                    PARTITION p2026_10 VALUES LESS THAN ('2026-11-01 00:00:00'),
                    PARTITION p2026_11 VALUES LESS THAN ('2026-12-01 00:00:00'),
                    PARTITION p2026_12 VALUES LESS THAN ('2027-01-01 00:00:00'),
                    PARTITION p2027_01 VALUES LESS THAN ('2027-02-01 00:00:00'),
                    PARTITION p2027_02 VALUES LESS THAN ('2027-03-01 00:00:00'),
                    PARTITION pmax VALUES LESS THAN MAXVALUE
                )
            ");
        }

        // 2. Tabel Partisi Riwayat Mutasi Stok (stock_movements_partitioned)
        if (! Schema::hasTable('stock_movements_partitioned')) {
            DB::statement("
                CREATE TABLE stock_movements_partitioned (
                    id BIGINT UNSIGNED NOT NULL,
                    tenant_id BIGINT UNSIGNED NOT NULL,
                    store_id BIGINT UNSIGNED NOT NULL,
                    product_id BIGINT UNSIGNED NOT NULL,
                    movement_type VARCHAR(32) NOT NULL,
                    quantity DECIMAL(15, 4) NOT NULL,
                    reference_type VARCHAR(64) NULL,
                    reference_id BIGINT UNSIGNED NULL,
                    cost_price DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
                    notes TEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id, created_at),
                    KEY idx_part_stock_mov_tenant_date (tenant_id, created_at, product_id),
                    KEY idx_part_stock_mov_ref (tenant_id, reference_type, reference_id)
                ) ENGINE=InnoDB
                PARTITION BY RANGE COLUMNS (created_at) (
                    PARTITION p2026_10 VALUES LESS THAN ('2026-11-01 00:00:00'),
                    PARTITION p2026_11 VALUES LESS THAN ('2026-12-01 00:00:00'),
                    PARTITION p2026_12 VALUES LESS THAN ('2027-01-01 00:00:00'),
                    PARTITION p2027_01 VALUES LESS THAN ('2027-02-01 00:00:00'),
                    PARTITION p2027_02 VALUES LESS THAN ('2027-03-01 00:00:00'),
                    PARTITION pmax VALUES LESS THAN MAXVALUE
                )
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements_partitioned');
        Schema::dropIfExists('order_items_partitioned');
    }
};

