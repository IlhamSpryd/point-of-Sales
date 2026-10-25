<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tabel purchase_additional_costs (Pencatatan Biaya Tambahan Pengadaan / Landed Cost)
        Schema::create('purchase_additional_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('goods_received_note_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('cost_code', 64);
            $table->string('category', 32);
            $table->string('description', 255);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1.000000);
            $table->string('allocation_method', 32)->default('BY_VALUE');
            $table->string('vendor_name', 150)->nullable();
            $table->string('invoice_reference', 100)->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('allocated_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'pac_tenant_id_id_unique');
            $table->unique(['tenant_id', 'cost_code'], 'pac_tenant_cost_code_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'pac_tenant_idempotency_unique');

            $table->index(['tenant_id', 'goods_received_note_id', 'status'], 'pac_tenant_grn_status_idx');
            $table->index(['tenant_id', 'store_id', 'created_at'], 'pac_tenant_store_created_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'pac_store_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'created_by'], 'pac_created_by_fk')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onDelete('restrict');
        });

        // Check constraint MySQL
        DB::statement('ALTER TABLE purchase_additional_costs ADD CONSTRAINT chk_pac_amount CHECK (amount > 0)');

        // 2. Tabel purchase_cost_allocations (Alokasi Biaya Tambahan per Baris GRN)
        Schema::create('purchase_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('purchase_additional_cost_id');
            $table->unsignedBigInteger('goods_received_note_line_id');
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('allocated_cost_per_unit', 18, 4)->default(0.0000);
            $table->decimal('weight_ratio', 18, 8)->default(0.00000000);
            $table->boolean('is_residual_line')->default(false);
            $table->boolean('is_post_receipt_adjustment')->default(false);
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'pca_tenant_id_id_unique');
            $table->index(['tenant_id', 'purchase_additional_cost_id'], 'pca_tenant_cost_idx');
            $table->index(['tenant_id', 'goods_received_note_line_id'], 'pca_tenant_grn_line_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'purchase_additional_cost_id'], 'pca_cost_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('purchase_additional_costs')
                ->onDelete('cascade');

            $table->foreign(['tenant_id', 'goods_received_note_line_id'], 'pca_grn_line_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('goods_received_note_lines')
                ->onDelete('restrict');
        });

        // Check constraint MySQL
        DB::statement('ALTER TABLE purchase_cost_allocations ADD CONSTRAINT chk_pca_amount CHECK (allocated_amount >= 0)');

        // 3. Seed Permissions RBAC Modul 5 Fase 7
        DB::table('permissions')->insertOrIgnore([
            ['permission_code' => 'purchasing.landed_cost.manage', 'description' => 'Mencatat biaya tambahan pengadaan dan mengeksekusi alokasi landed cost'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_cost_allocations');
        Schema::dropIfExists('purchase_additional_costs');
    }
};
