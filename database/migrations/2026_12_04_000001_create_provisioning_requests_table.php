<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 — Idempotency ledger untuk request provisioning dari website
 * marketing.
 *
 * Desain lifecycle (keputusan terdokumentasi di
 * docs/architecture/PROVISIONING.md):
 *  - Baris ledger dibuat DI LUAR transaksi bisnis utama dengan status
 *    'pending' SEBELUM transaksi dimulai. Ini satu-satunya cara untuk
 *    mendaftar "ada permintaan sedang berjalan" yang BERTAHAN bila transaksi
 *    bisnis rollback (crash sebelum commit): retry dengan key yang sama
 *    akan menemukan baris pending yang bisa di-resume/di-retry dengan aman
 *    karena transaksi utama dijamin tidak meninggalkan partial state.
 *  - Transaksi bisnis (Tenant+Role+Store+User+audit) kemudian berjalan
 *    atomik. Bila commit sukses, baris ledger dinaikkan menjadi 'completed'
 *    dengan result_tenant_id — UPDATE SETELAH commit (bukan di dalam
 *    transaksi), sehingga kegagalan update ledger tidak membatalkan tenant.
 *    Kasus "commit sukses tapi ledger masih pending" (crash di antara
 *    keduanya) ditangani oleh recovery: pencarian tenant berdasarkan
 *    owner_email + fingerprint waktu request di reconcile command.
 *  - Key yang sama dengan fingerprint berbeda HARUS ditolak 409 —
 *    request_fingerprint adalah hash dari (method, path, raw body) yang
 *    disimpan di sini sebagai referensi kebenaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->string('request_fingerprint', 64); // sha256(method|path|body)
            $table->string('owner_email', 255); // untuk recovery & lookup
            $table->string('status', 20)->default('pending'); // pending|processing|completed|retryable|failed
            $table->unsignedBigInteger('result_tenant_id')->nullable(); // set setelah commit
            $table->unsignedBigInteger('result_user_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable(); // klaim processing berjangka
            $table->text('error_message')->nullable(); // kode aman, tanpa detail sensitif
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->index(['owner_email', 'status']);
            $table->index(['status', 'started_at']);
            $table->index('result_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_requests');
    }
};
