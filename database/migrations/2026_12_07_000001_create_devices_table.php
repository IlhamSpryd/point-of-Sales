<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('device_code', 50);
            $table->string('device_name', 100);
            $table->string('device_type', 50)->default('pos_terminal');
            $table->string('pairing_token', 64)->nullable()->unique();
            $table->timestamp('pairing_token_expires_at')->nullable();
            $table->string('device_fingerprint', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('pending'); // pending, paired, revoked
            $table->timestamp('last_seen_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign Key Komposit: Device terikat mutlak ke satu Store spesifik dalam Tenant
            $table->foreign(['tenant_id', 'store_id'], 'devices_store_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('CASCADE')->onUpdate('CASCADE');

            $table->foreign('tenant_id', 'devices_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE')->onUpdate('CASCADE');

            // Unique index komposit untuk relasi anak di masa depan & pencegahan duplikasi kode di cabang
            $table->unique(['tenant_id', 'id'], 'devices_tenant_id_id_unique');
            $table->unique(['tenant_id', 'store_id', 'device_code'], 'devices_store_code_unique');

            $table->index(['tenant_id', 'device_fingerprint'], 'devices_fingerprint_index');
            $table->index(['tenant_id', 'status'], 'devices_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
