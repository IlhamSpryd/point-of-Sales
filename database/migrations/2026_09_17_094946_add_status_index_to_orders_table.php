<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_status', 'orders_order_status_index');
            // Dashboard kasir & laporan sering filter WHERE order_status = 'pending'
            // Tanpa index, ini full table scan saat data sudah puluhan ribu baris
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_order_status_index');
        });
    }
};
