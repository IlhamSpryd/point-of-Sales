<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // PB1 (Pajak Restoran) dan Service Charge adalah dua basis hukum
            // berbeda -- WAJIB dipisah, tidak boleh digabung ke tax_amount,
            // agar struk sesuai standar akuntansi F&B Indonesia.
            $table->unsignedBigInteger('service_charge_amount')->default(0)->after('tax_amount');

            // Audit trail void: menutup celah Zero-Trust dimana pembatalan
            // transaksi lunas tidak punya jejak siapa/kapan/kenapa. Selaras
            // dengan permission 'can_void_order' yang sudah ada di
            // roles.permissions tapi belum punya rumah data sampai sekarang.
            $table->foreignId('voided_by')->nullable()->after('order_status')
                ->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
            $table->timestamp('voided_at')->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['service_charge_amount', 'voided_by', 'void_reason', 'voided_at']);
        });
    }
};
