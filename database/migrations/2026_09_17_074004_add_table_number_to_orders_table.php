<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // PERUBAHAN ARSITEKTUR: bukan lagi string bebas (table_number) yang
            // bisa diketik sembarangan oleh pelanggan, melainkan foreign key
            // terverifikasi ke tabel 'tables'. Nullable karena transaksi dari
            // Kasir (via POS reguler) tidak memakai konsep meja sama sekali.
            // restrictOnDelete: mencegah Admin menghapus data meja yang masih
            // punya riwayat pesanan (konsisten dgn pola FK lain di proyek ini).
            $table->foreignId('table_id')->nullable()->after('user_id')
                ->constrained('tables')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropColumn('table_id');
        });
    }
};
