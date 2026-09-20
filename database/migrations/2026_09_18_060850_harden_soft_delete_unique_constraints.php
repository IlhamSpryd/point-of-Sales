<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CELAH KRITIKAL (lihat riwayat audit): `users.email` dan
     * `categories.category_name` memakai SoftDeletes tapi unique index-nya
     * polos, sehingga email/nama yang sudah "diarsipkan" terkunci selamanya.
     *
     * REVISI KEDUA -- kenapa BUKAN UNIX_TIMESTAMP(deleted_at):
     * TIMESTAMP di kolom ini tidak punya presisi sub-detik. Bulk soft-delete
     * (misal: `Category::whereIn(...)->delete()`) mengevaluasi NOW() SATU KALI
     * untuk seluruh baris dalam satu query -- artinya dua baris yang diarsipkan
     * dalam operasi bulk yang sama akan mendapat `deleted_at` IDENTIK, sehingga
     * UNIX_TIMESTAMP-nya collide dan constraint gagal.
     *
     * SOLUSI FINAL: manfaatkan semantik native SQL bahwa unique index TIDAK
     * PERNAH menegakkan constraint pada nilai NULL (NULL != NULL secara SQL).
     * - Baris AKTIF (deleted_at IS NULL)     -> key = 0     (dipaksa collide
     *   satu sama lain -> uniqueness sungguhan ditegakkan, ini yang kita mau).
     * - Baris SOFT-DELETED (deleted_at NOT NULL) -> key = NULL (MySQL/MariaDB
     *   tidak pernah membandingkan NULL dengan NULL -> otomatis kebal collision,
     *   berapa pun baris yang diarsipkan bersamaan, apapun timestamp-nya).
     *
     * Tidak butuh referensi ke `id` (AUTO_INCREMENT) sama sekali, sehingga
     * aman dari limitasi MariaDB 10.4 yang melarang generated column
     * merujuk kolom AUTO_INCREMENT.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('email_uniqueness_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 0 ELSE NULL END')
                ->after('deleted_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['email', 'email_uniqueness_key'], 'users_email_active_unique');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('name_uniqueness_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 0 ELSE NULL END')
                ->after('deleted_at');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_category_name_unique');
            $table->unique(['category_name', 'name_uniqueness_key'], 'categories_category_name_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_active_unique');
            $table->unique('email', 'users_email_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_uniqueness_key');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_category_name_active_unique');
            $table->unique('category_name', 'categories_category_name_unique');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('name_uniqueness_key');
        });
    }
};
