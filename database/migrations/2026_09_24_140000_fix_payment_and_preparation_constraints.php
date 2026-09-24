<?php

// PATCH FOR F-02/F-03: relax payments.amount CHECK (>0 → >=0) agar void
// counter-entry bisa dicatat. Tambah 'voided' ke status CHECK.
// PATCH FOR F-08: tighten order_items.preparation_status CHECK ke values
// yang BENAR-BENAR dipakai oleh PreparationStatus enum PHP, hapus
// 'preparing' dan 'delivered' yang tidak punya case di enum.

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

        // --- payments.amount: relax >0 ke >=0 untuk void counter-entry ---
        $this->dropCheck('payments', 'chk_payments_amount_positive');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_positive
            CHECK (amount >= 0)");

        // --- payments.status: tambah 'voided' ---
        $this->dropCheck('payments', 'chk_payments_status');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status
            CHECK (status IN ('pending','captured','failed','refunded','voided'))");

        // --- order_items.preparation_status: ketatkan ke enum PHP ---
        $this->dropCheck('order_items', 'check_order_items_preparation_status');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT check_order_items_preparation_status
            CHECK (preparation_status IN ('pending','brewing','ready'))");
    }

    public function down(): void
    {
        // Sengaja kosong: rollback ke constraint usang bukan opsi aman.
    }

    private function dropCheck(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        } catch (\Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            } catch (\Throwable) {
                // Constraint mungkin belum ada.
            }
        }
    }
};
