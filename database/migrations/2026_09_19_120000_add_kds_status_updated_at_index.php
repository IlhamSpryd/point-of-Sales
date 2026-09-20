<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * brewingItems() dan readyItems() di KDS Board mengurutkan dan memfilter
     * berdasarkan `updated_at`, sementara index komposit yang sudah ada
     * (preparation_status, created_at) hanya menutupi pendingItems().
     * Tanpa index ini, setiap poll 5 detik dari setiap layar KDS memicu
     * filesort tambahan di MySQL.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['preparation_status', 'updated_at'], 'order_items_kds_status_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_kds_status_updated_index');
        });
    }
};
