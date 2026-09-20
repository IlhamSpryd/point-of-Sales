<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE1] Emergency Backend Hardening | 2026-09-21
return new class extends Migration
{
    /**
     * Ledger kompensasi stok. HANYA mencatat peristiwa pengembalian stok
     * (bukan seluruh mutasi stok) -- dibuat khusus menjamin idempotency
     * saat webhook Midtrans (deny/cancel/expire) mengembalikan stok yang
     * sempat "direservasi" saat order dibuat.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->string('type', 30);
            $table->integer('quantity');
            $table->text('reason');

            // Kunci idempotency deterministik: 'stock_restore:{order_code}:{order_item_id}'.
            // Lapisan pertahanan KETIGA (selain lockForUpdate() pada Order & Product)
            // yang menjamin "exactly-once" walau method ini suatu saat dipanggil
            // dari jalur tanpa lock yang sama.
            $table->string('idempotency_key')->unique();

            $table->timestamps();

            $table->index(['order_id', 'type'], 'stock_movements_order_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
