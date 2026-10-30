<?php

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

        // --- orders.payment_method ---
        $this->dropCheck('orders', 'check_orders_payment_method');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_orders_payment_method
            CHECK (payment_method IN ('cash','qris','ewallet','card','gift_card','store_credit'))");

        // --- payments.payment_method ---
        $this->dropCheck('payments', 'chk_payments_method_enum');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method_enum
            CHECK (payment_method IN ('cash','qris','ewallet','card','gift_card','store_credit'))");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->dropCheck('orders', 'check_orders_payment_method');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_orders_payment_method
            CHECK (payment_method IN ('cash','qris','ewallet','card'))");

        $this->dropCheck('payments', 'chk_payments_method_enum');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method_enum
            CHECK (payment_method IN ('cash','qris','ewallet','card'))");
    }

    private function dropCheck(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        } catch (\Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            } catch (\Throwable) {
                // Ignore if not present
            }
        }
    }
};
