<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul CRM & Loyalty | 2026-09-22
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->unique()->nullable();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable()->unique();
            $table->date('date_of_birth')->nullable();
            $table->date('join_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            // Pola IDENTIK dengan users.email / categories.category_name /
            // tables.table_name (lihat migrasi hardening Node 1): nomor HP
            // adalah identifier utama loyalty yang WAJIB unik untuk
            // pelanggan aktif, TAPI pelanggan yang di-soft-delete tidak
            // boleh mengunci nomor itu selamanya (nomor HP daur ulang oleh
            // provider, atau pelanggan lama minta datanya dihapus lalu
            // mendaftar ulang di kemudian hari).
            $table->unsignedBigInteger('phone_uniqueness_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 0 ELSE NULL END');

            $table->unique(['phone', 'phone_uniqueness_key'], 'customers_phone_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
