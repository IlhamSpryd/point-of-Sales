<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 1 — Modul 3
 * Tabel brands: Tenant-scoped master merek dagang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 64);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unsignedBigInteger('name_uniqueness_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 0 ELSE NULL END');

            $table->foreign('tenant_id', 'brands_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE');

            // Unique composite untuk referensi composite FK dari tabel anak
            $table->unique(['tenant_id', 'id'], 'brands_tenant_id_id_unique');
            // Unique bisnis per tenant (kebal collision saat soft-deleted)
            $table->unique(['tenant_id', 'code', 'name_uniqueness_key'], 'brands_tenant_code_active_unique');
            $table->unique(['tenant_id', 'name', 'name_uniqueness_key'], 'brands_tenant_name_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
