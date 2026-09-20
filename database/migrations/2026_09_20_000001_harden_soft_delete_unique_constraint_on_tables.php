<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * [DB-003 - FIX - AUDIT INTEGRITAS DATABASE]
     *
     * Migrasi `2026_09_18_234707_update_tables_structure_for_enterprise`
     * menambahkan SoftDeletes ke tabel `tables` SEKALIGUS mengganti nama
     * kolom `table_number` menjadi `table_name` -- namun unique index bawaan
     * `table_number` (dari migrasi paling awal,
     * `2026_09_17_074003_create_tables_table`) tetap polos: unique index
     * biasa, bukan varian yang mengabaikan baris soft-deleted.
     *
     * Pola perbaikan ini SUDAH pernah diterapkan sebelumnya pada
     * `users.email` dan `categories.category_name` lewat migrasi
     * `2026_09_18_060850_harden_soft_delete_unique_constraints` (berjalan
     * jam 06:08) -- TAPI migrasi itu berjalan LEBIH DULU, sebelum `tables`
     * mendapatkan SoftDeletes (jam 23:47 pada hari yang sama), sehingga
     * tabel `tables` terlewat dari perbaikan yang sama.
     *
     * DAMPAK sebelum migrasi ini: begitu Owner "menghapus" (soft-delete)
     * sebuah meja bernama "Meja 12", nama itu TERKUNCI SELAMANYA -- tidak
     * ada meja baru yang bisa memakai nama itu lagi, baik lewat unique index
     * database maupun lewat validasi `unique:tables,table_name` di
     * StoreTableRequest/UpdateTableRequest (rule ini membaca tabel mentah,
     * bukan lewat scope Eloquent, sehingga tetap menghitung baris
     * soft-deleted).
     *
     * CATATAN TEKNIS PENTING: saat `renameColumn('table_number','table_name')`
     * dijalankan, MySQL/MariaDB TIDAK ikut mengganti nama index unique lama
     * -- index tersebut kemungkinan besar masih bernama
     * `tables_table_number_unique` meski kini mengindeks kolom `table_name`.
     * Migrasi ini SENGAJA mencari nama index unique yang sesungguhnya secara
     * dinamis lewat `SHOW INDEX`, bukan menebak nama secara statis, agar
     * tahan terhadap ketidakpastian nama index pasca-rename.
     *
     * SOLUSI: pola identik dengan migrasi users/categories -- generated
     * column yang bernilai 0 untuk baris aktif (dipaksa collide -> uniqueness
     * sungguhan ditegakkan) dan NULL untuk baris soft-deleted (NULL != NULL
     * secara semantik SQL -> tidak pernah collide, kebal duplikasi berapa
     * pun banyaknya baris yang diarsipkan).
     */
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->unsignedBigInteger('table_name_uniqueness_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 0 ELSE NULL END')
                ->after('deleted_at');
        });

        // Cari nama index unique yang SESUNGGUHNYA pada kolom table_name,
        // alih-alih menebak nama statis (lihat catatan teknis di atas).
        $oldIndexName = 'tables_table_number_unique';
        
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $indexes = DB::select("SHOW INDEX FROM `tables` WHERE Column_name = 'table_name' AND Non_unique = 0 AND Key_name != 'PRIMARY'");
            if (!empty($indexes)) {
                $oldIndexName = $indexes[0]->Key_name;
            }
        }

        if ($oldIndexName) {
            Schema::table('tables', function (Blueprint $table) use ($oldIndexName) {
                $table->dropUnique($oldIndexName);
            });
        }

        Schema::table('tables', function (Blueprint $table) {
            $table->unique(['table_name', 'table_name_uniqueness_key'], 'tables_table_name_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropUnique('tables_table_name_active_unique');
        });

        Schema::table('tables', function (Blueprint $table) {
            $table->unique('table_name', 'tables_table_name_unique');
            $table->dropColumn('table_name_uniqueness_key');
        });
    }
};
