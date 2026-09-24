<?php

// PATCH FOR S-01: constraint lama tidak memuat 'cancelled','expired','failed',
// 'completed','void' dan 'brewing'. Migrasi BARU (jangan edit migrasi lama yang
// sudah berjalan).

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // --- orders.order_status ---
        $this->dropCheck('orders', 'check_orders_status');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_orders_status
            CHECK (order_status IN ('pending','paid','cancelled','expired','failed','completed','void'))");

        // --- orders.payment_method --- (tambah 'card' yang didukung PaymentMethodEnum)
        $this->dropCheck('orders', 'check_orders_payment_method');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_orders_payment_method
            CHECK (payment_method IN ('cash','qris','ewallet','card'))");

        // --- order_items.preparation_status --- (tambah 'brewing' dari PreparationStatus enum)
        $this->dropCheck('order_items', 'check_order_items_preparation_status');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT check_order_items_preparation_status
            CHECK (preparation_status IN ('pending','preparing','brewing','ready','delivered'))");
    }

    public function down(): void
    {
        // Sengaja kosong: kembali ke constraint yang salah bukan opsi yang aman.
    }

    private function dropCheck(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        } catch (Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            } catch (Throwable) {
                // Constraint mungkin belum ada (migrasi lama belum pernah dijalankan).
            }
        }
    }
};
