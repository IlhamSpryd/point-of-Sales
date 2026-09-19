<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // nullOnDelete (BUKAN restrictOnDelete): jika master data diskon
            // dihapus di masa depan, nominal potongan yang sudah tercatat di
            // discount_amount TETAP UTUH (sudah beku), hanya referensinya
            // yang jadi null -- struk lama tidak boleh berubah nilainya.
            $table->foreignId('discount_id')->nullable()->after('shift_id')
                ->constrained('discounts')->nullOnDelete();
            $table->unsignedBigInteger('discount_amount')->default(0)->after('subtotal_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn(['discount_id', 'discount_amount']);
        });
    }
};
