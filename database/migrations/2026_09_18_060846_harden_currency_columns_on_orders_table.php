<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konversi DECIMAL(15,2) -> BIGINT UNSIGNED aman karena
     * TransactionService::calculateOrderTotals() SELALU (int) round(...)
     * sebelum INSERT -- secara historis tidak pernah ada nilai desimal
     * pecahan tersimpan di kolom-kolom ini. Rupiah tidak punya subunit
     * desimal praktis, jadi menyeragamkan ke BIGINT UNSIGNED menghilangkan
     * ambiguitas tipe DAN mencegah nominal negatif masuk ke ledger.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('subtotal_amount')->default(0)->change();
            $table->unsignedBigInteger('tax_amount')->default(0)->change();
            $table->unsignedBigInteger('cash_received')->nullable()->change();
            $table->unsignedBigInteger('order_amount')->default(0)->change();
            $table->unsignedBigInteger('order_change')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 15, 2)->default(0)->change();
            $table->decimal('tax_amount', 15, 2)->default(0)->change();
            $table->decimal('cash_received', 15, 2)->nullable()->change();
            $table->bigInteger('order_amount')->default(0)->change();
            $table->bigInteger('order_change')->default(0)->change();
        });
    }
};
