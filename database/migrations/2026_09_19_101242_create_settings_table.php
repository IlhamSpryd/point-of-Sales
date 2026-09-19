<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Settings: key-value store agar Administrator bisa mengubah
     * konfigurasi bisnis (tarif pajak, pembulatan, dll) langsung dari
     * halaman Pengaturan Sistem di UI -- tanpa perlu akses server atau
     * redeploy kode setiap kali pemerintah mengubah tarif PPN, misalnya.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            // Petunjuk casting di sisi aplikasi: string|integer|boolean|json
            $table->string('type', 20)->default('string');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
