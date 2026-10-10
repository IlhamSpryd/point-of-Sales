<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 — Anti-replay store berbasis database.
 *
 * Keputusan D6: meskipun CACHE_STORE=database (persisten), INSERT unik di
 * tabel ini lebih kuat daripada Cache::add() untuk anti-replay karena:
 *  1. Unique constraint PRIMARY KEY menegakkan "sekali pakai" pada level
 *     engine, bukan level aplikasi — atomik bahkan pada race dua worker.
 *  2. Masa retensi nonce bisa ditegakkan lewat scheduled cleanup command,
 *     sedangkan cache database Laravel tidak menjamin eviction deterministik
 *     untuk keys berumur panjang.
 *  3. Invariant business ini terikat pada audit SaaS — layak berada di schema.
 *
 * Nonce disimpan minimal 2x jendela timestamp (±300s => retensi >= 15 menit;
 * default 30 menit = aman terhadap clock drift kecil di antara node).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary(); // dari header X-Nonce (UUID)
            $table->unsignedBigInteger('expires_at_epoch'); // timestamp window batas
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_nonces');
    }
};
