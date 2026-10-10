<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 — Transactional email outbox.
 *
 * Keputusan D8: email onboarding tidak boleh membuat Tenant yang sudah
 * commit menjadi gagal. Pola outbox:
 *  - Baris outbox di-insert DI DALAM transaksi provisioning utama —
 *    ikut commit/rollback bersama resource bisnis (tidak ada event hilang
 *    karena crash antara commit dan enqueue).
 *  - Command scheduler `emails:process-outbox` (setiap menit) men-poll
 *    baris 'pending' dan mengirim via Mailer, lalu menandai 'sent'.
 *  - Idempotency per-event: satu event = satu recipient terdefinisi;
 *    duplicate-send terjadi hanya bila crash TERJADI SETELAH Mail::send
 *    sukses tapi sebelum flag 'sent' tersimpan — dianggap acceptable untuk
 *    email onboarding (duplicate email tidak berdampak bisnis, dan token
 *    di dalamnya tetap valid sampai dipakai/expired — BUKAN di-extend).
 *  - Retry: attempts, last_error, next_attempt_at (exponential-ish backoff
 *    sederhana, max attempts via config).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50); // owner_onboarding | owner_resend_token | ...
            $table->string('recipient_email', 255);
            $table->string('subject', 255);
            $table->text('body_text'); // template sederhana, tanpa HTML multipart
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending|sent|failed|expired
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
    }
};
