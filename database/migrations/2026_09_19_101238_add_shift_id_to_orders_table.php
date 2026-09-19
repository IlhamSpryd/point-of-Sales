<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Setiap transaksi tunai/non-tunai dari Kasir dikaitkan ke shift
            // yang sedang aktif saat itu -- dasar untuk laporan rekonsiliasi
            // kas per-shift. Nullable karena self-order (customer QR) tidak
            // pernah punya konsep shift kasir sama sekali.
            $table->foreignId('shift_id')->nullable()->after('user_id')
                ->constrained('shifts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
