<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activity Log generik: mencatat aksi sensitif apa pun di seluruh
     * sistem (void order, terapkan diskon, ubah harga produk, tutup
     * shift, dll) -- siapa pelakunya, kapan, dan apa yang berubah.
     * Kebutuhan Zero-Trust dasar untuk sistem yang menangani uang riil.
     * subject_type/subject_id bersifat polymorphic (bisa menunjuk ke
     * Model apa pun -- Order, Product, Shift) tanpa perlu tabel log
     * terpisah untuk setiap jenis entitas.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // contoh: 'order.void', 'discount.apply', 'shift.close'
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'activity_logs_subject_index');
            $table->index('created_at', 'activity_logs_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
