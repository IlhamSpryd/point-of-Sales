<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE1] Zero-Trust hardening: satu shift open per kasir | 2026-09-21
return new class extends Migration
{
    /**
     * ShiftManager::openShift() sebelumnya HANYA menjaga invariant "satu
     * shift terbuka per kasir" lewat pengecekan level-APLIKASI
     * (Shift::where(...)->exists()) TANPA lock atau constraint database --
     * klasik TOCTOU (Time-Of-Check-Time-Of-Use). Dua klik ganda atau dua
     * tab browser yang mengirim request nyaris bersamaan bisa LOLOS
     * pengecekan exists() secara paralel sebelum salah satunya sempat
     * commit INSERT, menghasilkan DUA shift 'open' sekaligus untuk kasir
     * yang sama -- merusak rekonsiliasi kas per-shift secara permanen
     * (closeShift() hanya menutup satu baris, sisanya menggantung).
     *
     * SOLUSI: pola IDENTIK dengan migrasi
     * 2026_09_18_060850_harden_soft_delete_unique_constraints dan
     * 2026_09_20_000001_harden_soft_delete_unique_constraint_on_tables --
     * generated column bernilai `user_id` HANYA saat status='open' (dipaksa
     * collide -> uniqueness ditegakkan DATABASE, bukan aplikasi) dan NULL
     * untuk status lainnya (NULL != NULL secara semantik SQL -> shift yang
     * sudah closed tidak pernah collide, berapa pun riwayat shift closed
     * yang dimiliki kasir tersebut).
     *
     * PERLU VERIFIKASI DOCS: virtualAs() memerlukan MySQL/MariaDB (generated
     * columns). Konsisten dengan dua migrasi hardening sebelumnya di proyek
     * ini yang sudah mengasumsikan driver yang sama, jadi tidak menambah
     * risiko kompatibilitas baru.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('open_shift_lock_key')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'open' THEN user_id ELSE NULL END")
                ->after('status');

            $table->unique('open_shift_lock_key', 'shifts_one_open_per_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique('shifts_one_open_per_user_unique');
            $table->dropColumn('open_shift_lock_key');
        });
    }
};
