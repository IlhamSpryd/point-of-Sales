<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            // Menyimpan "cetakan beku" dari pilihan varian pelanggan (Ice, Less Sugar, dst)
            // dalam format JSON. Disebut "beku" karena data ini TIDAK boleh berubah lagi
            // walaupun admin nanti mengubah/menghapus master data Modifier — struk yang
            // sudah tercetak harus tetap menampilkan apa yang benar-benar dipesan pelanggan.
            $table->json('options')->nullable()->after('order_subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
