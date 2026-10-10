<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 — Token onboarding Owner pertama (magic link).
 *
 * Desain (keputusan D5):
 *  - Token dikirim ke Owner sebagai string acak ber-entropy tinggi
 *    (48 karakter random base64 ~ 288 bit), dikirim via email SETELAH
 *    provisioning commit.
 *  - Di database HANYA disimpan SHA-256 hash token (bukan plaintext) —
 *    bocornya dump DB tidak langsung menghasilkan token valid.
 *  - Sekali pakai: consumed_at diisi pada penukaran pertama yang sukses.
 *  - Kedaluwarsa: expires_at default +48 jam (config).
 *  - Token TERIKAT ke (user_id, tenant_id) — validasi penerima dilakukan
 *    server-side, token tidak dapat memindahkan owner ke tenant lain.
 *  - Tidak boleh mengubah email akun: kolom user_id dikunci ke user Owner
 *    yang dibuat provisioning; flow penukaran tidak menyentuh kolom email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_onboarding_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique(); // sha256 hex of plaintext token
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedInteger('send_attempts')->default(0); // outbox retry counter
            $table->timestamps();

            $table->index('user_id');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_onboarding_tokens');
    }
};
