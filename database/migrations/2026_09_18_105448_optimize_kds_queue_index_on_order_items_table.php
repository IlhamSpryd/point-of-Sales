<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KDS Board akan di-poll setiap 5 detik oleh SETIAP layar dapur yang
     * terbuka (wire:poll.5s), masing-masing menjalankan 3 query terpisah
     * (pending/brewing/ready) yang SELALU memfilter preparation_status lalu
     * mengurutkan created_at/updated_at. Index tunggal lama tidak mengcover
     * ORDER BY -- composite index ini mengizinkan MySQL membaca hasil
     * sudah terurut langsung dari index tanpa filesort tambahan.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_preparation_status_index');
            $table->index(['preparation_status', 'created_at'], 'order_items_kds_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_kds_queue_index');
            $table->index('preparation_status', 'order_items_preparation_status_index');
        });
    }
};
