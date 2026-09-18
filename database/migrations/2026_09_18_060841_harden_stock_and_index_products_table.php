<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Zero-Trust hardening: stok TIDAK BOLEH pernah negatif secara
            // definisi bisnis. TransactionService sudah menjaga ini di level
            // aplikasi (lockForUpdate + validasi), tapi UNSIGNED di level
            // kolom adalah lapisan pertahanan kedua jika suatu saat ada
            // query manual/raw yang lolos dari Service layer.
            $table->unsignedInteger('stock')->default(0)->change();

            // Product::scopeAvailableForOrder() -> WHERE is_active = true
            // AND stock > 0. Query TERPANAS di seluruh sistem: dieksekusi
            // di setiap load menu self-order, load katalog kasir, DAN di
            // dalam TransactionService::buildOrderItemsWithStockLock()
            // (lockForUpdate). Equality (is_active) diletakkan lebih dulu
            // dari range (stock > 0).
            $table->index(['is_active', 'stock'], 'products_is_active_stock_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_is_active_stock_index');
            $table->integer('stock')->default(0)->change();
        });
    }
};
