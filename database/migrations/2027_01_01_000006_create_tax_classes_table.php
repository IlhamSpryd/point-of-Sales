<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 2 — Modul 3
 * Master Klasifikasi Pajak Katalog (tax_classes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 50);
            $table->string('name', 120);
            $table->decimal('rate', 8, 4)->default(0.0000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id', 'tax_classes_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE');

            $table->unique(['tenant_id', 'id'], 'tax_classes_tenant_id_id_unique');
            $table->unique(['tenant_id', 'code'], 'tax_classes_tenant_code_unique');
            $table->index(['tenant_id', 'is_active'], 'tax_classes_tenant_is_active_idx');
        });

        DB::statement('ALTER TABLE `tax_classes` ADD CONSTRAINT `tax_classes_rate_check` CHECK (`rate` >= 0 AND `rate` <= 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `tax_classes` DROP CHECK `tax_classes_rate_check`');
        Schema::dropIfExists('tax_classes');
    }
};
