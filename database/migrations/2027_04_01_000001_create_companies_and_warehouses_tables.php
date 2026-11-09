<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Implements canonical Tier 2 (companies) and Tier 4 (warehouses) per DEC-001.
     */
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('company_code', 32)->nullable();
                $table->string('company_name', 128);
                $table->string('legal_name', 191)->nullable();
                $table->string('npwp', 32)->nullable();
                $table->text('tax_address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_companies_tenant_id');
                $table->index(['tenant_id', 'is_active'], 'idx_companies_tenant_active');

                $table->foreign('tenant_id')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('warehouse_code', 64);
                $table->string('warehouse_name', 128);
                $table->string('warehouse_type', 32)->default('CENTRAL'); // CENTRAL, TRANSIT, RAW_MATERIAL, RETAIL
                $table->text('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_warehouses_tenant_id');
                $table->unique(['tenant_id', 'warehouse_code'], 'uq_warehouses_tenant_code');
                $table->index(['tenant_id', 'company_id'], 'idx_warehouses_tenant_company');
                $table->index(['tenant_id', 'store_id'], 'idx_warehouses_tenant_store');

                $table->foreign(['tenant_id', 'company_id'])
                    ->references(['tenant_id', 'id'])->on('companies')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'store_id'])
                    ->references(['tenant_id', 'id'])->on('stores')
                    ->onDelete('restrict')->onUpdate('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('companies');
    }
};
