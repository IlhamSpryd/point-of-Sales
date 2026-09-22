<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Jembatan Order <-> CRM | 2026-09-22
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nullable: mayoritas transaksi F&B tetap walk-in tanpa member.
            // nullOnDelete (BUKAN restrict): identitas pelanggan boleh
            // dihapus (hak penghapusan data pelanggan), tapi struk transaksi
            // historis yang sudah beku TIDAK BOLEH ikut hilang atau
            // memblokir penghapusan customer.
            $table->foreignId('customer_id')->nullable()->after('discount_id')
                ->constrained('customers')->nullOnDelete();

            $table->index('customer_id', 'orders_customer_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex('orders_customer_id_index');
            $table->dropColumn('customer_id');
        });
    }
};
