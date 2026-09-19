<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Shift/Cash Drawer: mencatat sesi kerja satu kasir dari
     * pembukaan (dengan modal awal kas) hingga penutupan (dengan
     * penghitungan fisik uang di laci). `expected_cash` dan
     * `cash_difference` sengaja DISIMPAN sebagai snapshot beku saat
     * penutupan -- bukan dihitung ulang saat dibaca -- konsisten
     * dengan pola "freeze at checkout" yang sudah dipakai di
     * order_items.options, agar laporan shift lama tidak berubah
     * meski ada void/refund di kemudian hari.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('opening_balance')->default(0);
            $table->unsignedBigInteger('closing_balance')->nullable();
            $table->unsignedBigInteger('expected_cash')->nullable();
            // Signed (BUKAN unsigned): selisih kas bisa minus (kurang) atau plus (lebih).
            $table->bigInteger('cash_difference')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'shifts_user_status_index');
            $table->index('opened_at', 'shifts_opened_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
