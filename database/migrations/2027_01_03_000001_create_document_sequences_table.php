<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 5 (Fase 0) — Tabel generator nomor dokumen sekuensial atomik per tenant,
 * store, tipe dokumen, dan periode (YYYYMM).
 *
 * Mengikuti spesifikasi Section 7.2 & 19 docs/implementationPlanModul5.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_sequences')) {
            Schema::create('document_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('scope_key', 64);
                $table->string('doc_type', 32);
                $table->string('period_key', 32)->default('global');
                $table->string('prefix', 32);
                $table->unsignedBigInteger('last_value')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'document_sequences_tenant_id_id_unique');
                $table->unique(['tenant_id', 'scope_key', 'doc_type', 'period_key'], 'doc_sequences_tenant_scope_type_period_unique');

                $table->foreign('tenant_id', 'doc_sequences_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->index(['tenant_id', 'store_id'], 'doc_sequences_tenant_store_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
