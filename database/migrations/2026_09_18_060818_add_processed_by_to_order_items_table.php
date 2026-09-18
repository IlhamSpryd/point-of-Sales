<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KDS (Kitchen Display System) & Audit Trail per-item:
     * Melacak barista/staf dapur mana yang MEMPROSES (meracik) satu baris
     * item pesanan tertentu. Ini terpisah dari `orders.user_id` (kasir yang
     * MENCATAT transaksi) karena satu order bisa dikerjakan lebih dari satu
     * staf sekaligus (satu meracik kopi, satu menyiapkan makanan).
     *
     * Sebelum migrasi ini, App\Models\OrderItem::processedBy() adalah
     * relasi "hantu" yang mengacu ke kolom yang tidak pernah ada di DB.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('processed_by')
                ->nullable()
                ->after('preparation_status')
                ->constrained('users')
                ->restrictOnDelete();

            // KDS akan polling "item apa saja yang sedang dikerjakan staf X
            // dengan status tertentu" -> index gabungan mempercepat query itu.
            $table->index(['processed_by', 'preparation_status'], 'order_items_processed_by_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_processed_by_status_index');
            $table->dropConstrainedForeignId('processed_by');
        });
    }
};
