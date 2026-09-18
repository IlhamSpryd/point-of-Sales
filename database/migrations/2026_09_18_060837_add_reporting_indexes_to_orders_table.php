<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // DashboardService menyaring `order_status = 'paid'` lalu
            // mengelompokkan berdasarkan created_at (7 hari terakhir,
            // bulan ini vs bulan lalu). Urutan kolom: equality dulu (status),
            // baru range (created_at) -> aturan dasar composite index.
            $table->index(['order_status', 'created_at'], 'orders_status_created_at_index');

            // ReportService (Laporan Penjualan) menyaring `order_status = 'paid'`
            // lalu whereBetween(order_date) -- kolom BEDA dari created_at,
            // wajib index komposit terpisah.
            $table->index(['order_status', 'order_date'], 'orders_status_order_date_index');

            // Index tunggal lama kini redundan: leftmost-prefix dari kedua
            // composite index di atas sudah mengcover query yang hanya
            // filter order_status saja. Dihapus untuk mengurangi overhead
            // penulisan (setiap INSERT/UPDATE order harus update SEMUA index)
            // di skenario throughput ratusan order/menit.
            $table->dropIndex('orders_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
            $table->dropIndex('orders_status_order_date_index');
            $table->index('order_status', 'orders_order_status_index');
        });
    }
};
