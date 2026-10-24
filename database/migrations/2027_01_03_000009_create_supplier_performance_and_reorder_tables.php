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
        // 1. Tabel supplier_performance_logs (Log Historis Kinerja Pengiriman & Harga Pemasok)
        Schema::create('supplier_performance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('goods_received_note_id');
            $table->unsignedBigInteger('goods_received_note_line_id');
            $table->string('purchasable_type', 32);
            $table->unsignedBigInteger('purchasable_id');
            $table->date('expected_delivery_date');
            $table->timestamp('received_at');
            $table->decimal('order_to_first_receipt_days', 8, 2)->nullable();
            $table->decimal('order_to_full_receipt_days', 8, 2)->nullable();
            $table->decimal('variance_from_expected_days', 8, 2)->default(0.00);
            $table->decimal('ordered_qty', 18, 4);
            $table->decimal('accepted_qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('effective_unit_cost', 18, 4);
            $table->timestamp('recorded_at');
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'spl_tenant_id_id_unique');
            $table->unique(['tenant_id', 'goods_received_note_line_id'], 'spl_tenant_grn_line_unique');

            $table->index(['tenant_id', 'supplier_id', 'purchasable_type', 'purchasable_id', 'recorded_at'], 'spl_tenant_supp_item_time_idx');
            $table->index(['tenant_id', 'store_id', 'recorded_at'], 'spl_tenant_store_recorded_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'spl_store_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'supplier_id'], 'spl_supplier_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'purchase_order_id'], 'spl_po_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('purchase_orders')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'goods_received_note_id'], 'spl_grn_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('goods_received_notes')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'goods_received_note_line_id'], 'spl_grn_line_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('goods_received_note_lines')
                ->onDelete('restrict');
        });

        // 2. Tabel reorder_suggestions (Rekomendasi Pemesanan Ulang Deterministik)
        Schema::create('reorder_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('purchasable_type', 32);
            $table->unsignedBigInteger('purchasable_id');
            $table->unsignedBigInteger('preferred_supplier_id')->nullable();
            $table->decimal('current_stock', 18, 4);
            $table->decimal('on_order_po_qty', 18, 4)->default(0.0000);
            $table->decimal('open_pr_qty', 18, 4)->default(0.0000);
            $table->decimal('net_inventory_position', 18, 4);
            $table->decimal('daily_consumption_rate', 18, 4)->default(0.0000);
            $table->decimal('lead_time_days', 8, 2)->default(0.00);
            $table->decimal('safety_stock', 18, 4)->default(0.0000);
            $table->decimal('reorder_point', 18, 4);
            $table->decimal('target_max_stock', 18, 4);
            $table->decimal('raw_deficit_qty', 18, 4);
            $table->decimal('moq', 18, 4)->default(1.0000);
            $table->decimal('order_multiple', 18, 4)->default(1.0000);
            $table->decimal('suggested_quantity', 18, 4);
            $table->string('purchase_uom', 30)->default('PCS');
            $table->decimal('estimated_unit_price', 18, 4)->default(0.0000);
            $table->decimal('estimated_total_cost', 18, 2)->default(0.00);
            $table->decimal('priority_score', 8, 2)->default(0.00);
            $table->string('status', 32)->default('open');
            $table->unsignedBigInteger('converted_purchase_request_id')->nullable();
            $table->string('dismissed_reason', 255)->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'rs_tenant_id_id_unique');
            $table->index(['tenant_id', 'store_id', 'status', 'priority_score'], 'rs_tenant_store_status_prio_idx');
            $table->index(['tenant_id', 'purchasable_type', 'purchasable_id'], 'rs_tenant_item_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'rs_store_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'preferred_supplier_id'], 'rs_supplier_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'converted_purchase_request_id'], 'rs_pr_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('purchase_requests')
                ->onDelete('restrict');
        });

        // 3. Seed Permissions RBAC Modul 5 Fase 8
        $permissions = [
            ['permission_code' => 'purchasing.reorder.view', 'description' => 'Melihat daftar saran pemesanan ulang barang'],
            ['permission_code' => 'purchasing.reorder.generate', 'description' => 'Menjalankan kalkulasi mesin rekomendasi pemesanan ulang'],
            ['permission_code' => 'purchasing.reorder.convert', 'description' => 'Mengonversi saran pemesanan ulang menjadi draf Purchase Request'],
        ];

        DB::table('permissions')->insertOrIgnore($permissions);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reorder_suggestions');
        Schema::dropIfExists('supplier_performance_logs');
    }
};
