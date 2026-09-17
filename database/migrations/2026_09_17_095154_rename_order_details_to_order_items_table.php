<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('order_details', 'order_items');
        // FK constraint (product_id, order_id) otomatis ikut ke nama tabel baru,
        // MySQL/MariaDB tidak butuh drop-recreate saat rename table.
    }

    public function down(): void
    {
        Schema::rename('order_items', 'order_details');
    }
};
