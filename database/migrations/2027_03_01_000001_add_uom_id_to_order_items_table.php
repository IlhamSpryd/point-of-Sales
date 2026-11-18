<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-009 / Audit Fix: Menambahkan kolom uom_id pada order_items agar
 * unit-of-measure yang dipilih kasir (mis. box vs pcs) tersimpan pada
 * baris penjualan — bukan hanya dipakai sementara untuk konversi stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Idempotent: kolom bisa sudah ada dari pemulihan manual.
            if (Schema::hasColumn('order_items', 'uom_id')) {
                return;
            }

            $table->unsignedBigInteger('uom_id')->nullable()->after('product_variant_id');

            // DEC-001: Kunci Asing Komposit berlingkup tenant ke units_of_measure(tenant_id, id)
            $table->foreign(['tenant_id', 'uom_id'], 'order_items_uom_fk')
                ->references(['tenant_id', 'id'])->on('units_of_measure')
                ->onUpdate('CASCADE')
                ->onDelete('RESTRICT');

            $table->index(['tenant_id', 'uom_id'], 'order_items_uom_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_uom_fk');
            $table->dropIndex('order_items_uom_idx');
            $table->dropColumn('uom_id');
        });
    }
};
