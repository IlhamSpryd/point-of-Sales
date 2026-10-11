<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 (DB-005, DB-006, DB-016, XD-02) — kolom yang dibutuhkan oleh
 * kebijakan ledger/outbox/readiness yang baru.
 *
 * Forward-only: tidak ada penghapusan nama migrasi lama maupun data. Migrasi
 * ini hanya MENAMBAH, jadi instalasi yang sudah berjalan tidak perlu
 * membongkar apa pun.
 *
 * Alasan tiap kolom:
 *  - `tenants.provisioning_idempotency_key` — kunci rekonsiliasi. Penanda
 *    idempotency yang ditulis DI DALAM transaksi bisnis (bersama tenant),
 *    sehingga keberadaannya menjadi bukti sah bahwa transaksi bisnis selesai.
 *    Tanpa kolom ini, rekonsiliasi hanya bisa menebak dari email pemilik, dan
 *    email tidak unik per request (satu pemilik boleh punya dua tenant).
 *  - `tenants.readiness_status` / `readiness_checked_at` — kontrak kesiapan
 *    tenant (XD-02). Nilainya dihitung ulang saat provisioning; disimpan
 *    sebagai kolom eksplisit, bukan disimpulkan ulang dari "apakah role
 *    Owner ada".
 *  - `email_outbox.next_attempt_at` juga dipakai sebagai lease klaim:
 *    baris 'processing' menyimpan waktu klaim KEDALUWARSA di kolom yang sama,
 *    sehingga tidak perlu kolom klaim terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'provisioning_idempotency_key')) {
                $table->string('provisioning_idempotency_key', 64)->nullable()->after('name');
                $table->unique('provisioning_idempotency_key', 'tenants_provisioning_key_unique');
            }

            if (! Schema::hasColumn('tenants', 'readiness_status')) {
                $table->string('readiness_status', 20)->default('pending')->after('provisioning_idempotency_key');
                $table->timestamp('readiness_checked_at')->nullable()->after('readiness_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_provisioning_key_unique');
            $table->dropColumn(['provisioning_idempotency_key', 'readiness_status', 'readiness_checked_at']);
        });
    }
};
