<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE3] Audit indexing throughput tinggi -- Node 3 Performance & Caching | 2026-09-22
return new class extends Migration
{
    /**
     * [OMEGA-NODE3] HASIL AUDIT (lihat PHASE 1 pada laporan): dari 3 index
     * komposit yang diminta, HANYA `orders` yang merupakan celah nyata.
     * Dua lainnya SUDAH ditambahkan oleh migrasi-migrasi sebelumnya:
     *
     *   - `payments(order_id, status)` SUDAH ADA sebagai
     *     `payments_order_status_index`, dibuat di
     *     `2026_09_22_020000_create_payments_table.php`.
     *   - `ingredient_stock_movements(ingredient_id, created_at)` SUDAH ADA
     *     sebagai `ingredient_stock_movements_ingredient_index`, dibuat di
     *     `2026_09_22_010002_create_ingredient_stock_movements_table.php`.
     *
     * Migrasi ini SENGAJA TIDAK mengulang keduanya. Index duplikat (walau
     * beda nama) hanya menambah overhead TULIS -- setiap INSERT ke ledger
     * append-only itu wajib memperbarui index tambahan yang tidak pernah
     * dipilih optimizer untuk dibaca, karena cakupannya identik dengan
     * index yang sudah ada -- tanpa manfaat baca sama sekali.
     *
     * CELAH NYATA pada `orders`: index existing `orders_status_order_date_index`
     * (order_status, order_date) hanya optimal ketika filter status DIISI
     * (order_status adalah kolom equality terdepan). Livewire
     * OrderHistory::orders() -- layar "Riwayat Pesanan" kasir -- memakai
     * default `statusFilter = 'all'`, yaitu kondisi PALING SERING terjadi.
     * Pada kondisi ini query hanya menyaring `order_date` (BETWEEN) TANPA
     * `order_status` sama sekali, sehingga index lama tidak bisa dipakai
     * secara efektif (leftmost-prefix rule). Index baru di bawah membalik
     * urutan kolom (order_date lebih dulu, memungkinkan Index Condition
     * Pushdown untuk order_status saat filter itu ADA) sekaligus menutup
     * dengan order_code sebagai covering column untuk SELECT yang ikut
     * mengambil kode pesanan tanpa perlu lompat balik ke baris utama.
     *
     * VERIFIKASI PANJANG KEY: order_date (3 byte, DATE) + order_status
     * varchar(255) + order_code varchar(255) berpotensi mendekati/melebihi
     * batas 767 byte lama InnoDB, TAPI point_of_sales.sql (dump database
     * NYATA proyek ini) membuktikan index `users_email_active_unique`
     * (email varchar(255) + kolom lain) SUDAH berjalan sukses di server
     * MariaDB 10.4.32 mereka -- artinya `innodb_large_prefix` (limit
     * 3072 byte) sudah aktif di server tersebut. Index 3-kolom di bawah
     * ini AMAN dieksekusi pada server yang sama.
     *
     * CATATAN DI LUAR CAKUPAN: pencarian `order_code LIKE '%...%'`
     * (wildcard di KEDUA ujung, dipakai OrderHistory::updatedSearch())
     * tidak bisa memakai index B-Tree apa pun -- itu keterbatasan SQL,
     * bukan celah index. Solusinya (jika volume data menuntut) adalah
     * FULLTEXT index atau search engine terpisah, bukan index tambahan.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['order_date', 'order_status', 'order_code'], 'orders_date_status_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_date_status_code_index');
        });
    }
};
