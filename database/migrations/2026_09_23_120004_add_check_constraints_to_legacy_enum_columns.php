<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_orders_status CHECK (order_status IN ('pending', 'paid', 'canceled', 'refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_orders_payment_method CHECK (payment_method IN ('cash', 'qris', 'ewallet', 'card'))");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT check_order_items_preparation_status CHECK (preparation_status IN ('pending', 'preparing', 'ready', 'delivered'))");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT check_orders_status');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT check_orders_payment_method');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT check_order_items_preparation_status');
    }
};
