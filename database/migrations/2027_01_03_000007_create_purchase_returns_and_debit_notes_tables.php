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
        // 1. Tabel purchase_returns (Header Retur Pembelian)
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('goods_received_note_id');
            $table->string('return_number', 64);
            $table->string('status', 32)->default('draft');
            $table->string('reason', 255);
            $table->decimal('subtotal_amount', 18, 2)->default(0.00);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2)->default(0.00);
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'pret_tenant_id_id_unique');
            $table->unique(['tenant_id', 'return_number'], 'pret_tenant_return_num_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'pret_tenant_idempotency_unique');

            $table->index(['tenant_id', 'goods_received_note_id', 'status'], 'pret_tenant_grn_status_idx');
            $table->index(['tenant_id', 'supplier_id', 'status'], 'pret_tenant_supp_status_idx');
            $table->index(['tenant_id', 'store_id', 'created_at'], 'pret_tenant_store_created_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'pret_store_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'supplier_id'], 'pret_supplier_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'goods_received_note_id'], 'pret_grn_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('goods_received_notes')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'created_by'], 'pret_created_by_fk')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onDelete('restrict');
        });

        // Check constraint MySQL
        DB::statement('ALTER TABLE purchase_returns ADD CONSTRAINT chk_pret_amounts CHECK (subtotal_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0)');

        // 2. Tabel purchase_return_lines (Baris Item Retur Pembelian)
        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('goods_received_note_line_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedInteger('line_number');
            $table->string('purchasable_type', 32);
            $table->unsignedBigInteger('purchasable_id');
            $table->decimal('return_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('subtotal', 18, 2)->default(0.00);
            $table->decimal('tax_rate', 8, 4)->default(0.0000);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2)->default(0.00);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unsignedBigInteger('ingredient_stock_movement_id')->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'pret_line_tenant_id_id_unique');
            $table->index(['tenant_id', 'purchase_return_id'], 'pret_line_tenant_return_idx');
            $table->index(['tenant_id', 'goods_received_note_line_id'], 'pret_line_tenant_grn_line_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'purchase_return_id'], 'pret_line_return_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('purchase_returns')
                ->onDelete('cascade');

            $table->foreign(['tenant_id', 'goods_received_note_line_id'], 'pret_line_grn_line_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('goods_received_note_lines')
                ->onDelete('restrict');
        });

        // Check constraint MySQL
        DB::statement('ALTER TABLE purchase_return_lines ADD CONSTRAINT chk_pret_line_qty CHECK (return_qty > 0 AND unit_cost >= 0 AND total_amount >= 0)');

        // 3. Tabel purchase_debit_notes (Nota Debit Pembelian / Pengurang Hutang Pemasok)
        Schema::create('purchase_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_return_id')->nullable();
            $table->string('debit_note_number', 64);
            $table->date('debit_note_date');
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('subtotal_amount', 18, 2)->default(0.00);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2)->default(0.00);
            $table->decimal('applied_amount', 18, 2)->default(0.00);
            $table->decimal('remaining_amount', 18, 2)->default(0.00);
            $table->string('status', 32)->default('draft');
            $table->string('supplier_credit_note_number', 100)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('issued_at')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'pdn_tenant_id_id_unique');
            $table->unique(['tenant_id', 'debit_note_number'], 'pdn_tenant_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'pdn_tenant_idempotency_unique');

            $table->index(['tenant_id', 'supplier_id', 'status'], 'pdn_tenant_supp_status_idx');
            $table->index(['tenant_id', 'store_id', 'debit_note_date'], 'pdn_tenant_store_date_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'pdn_store_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'supplier_id'], 'pdn_supplier_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'created_by'], 'pdn_created_by_fk')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onDelete('restrict');
        });

        // Check constraint MySQL
        DB::statement('ALTER TABLE purchase_debit_notes ADD CONSTRAINT chk_pdn_amounts CHECK (applied_amount >= 0 AND remaining_amount >= 0 AND total_amount >= 0)');

        // 4. Tabel purchase_debit_note_applications (Alokasi Pemotongan Nota Debit ke Faktur Pemasok)
        Schema::create('purchase_debit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('purchase_debit_note_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->decimal('applied_amount', 18, 2);
            $table->timestamp('applied_at');
            $table->unsignedBigInteger('applied_by');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'pdna_tenant_id_id_unique');
            $table->index(['tenant_id', 'purchase_debit_note_id'], 'pdna_tenant_debit_note_idx');
            $table->index(['tenant_id', 'supplier_invoice_id'], 'pdna_tenant_invoice_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'purchase_debit_note_id'], 'pdna_dn_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('purchase_debit_notes')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'supplier_invoice_id'], 'pdna_inv_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('supplier_invoices')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'applied_by'], 'pdna_applied_by_fk')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onDelete('restrict');
        });

        // Check constraint MySQL
        DB::statement('ALTER TABLE purchase_debit_note_applications ADD CONSTRAINT chk_pdna_amount CHECK (applied_amount > 0)');

        // 5. Seed Permissions RBAC Modul 5 Fase 6
        $permissions = [
            ['permission_code' => 'purchasing.return.view', 'description' => 'Melihat daftar dan detail retur pembelian'],
            ['permission_code' => 'purchasing.return.create', 'description' => 'Membuat draf retur pembelian'],
            ['permission_code' => 'purchasing.return.approve', 'description' => 'Menyetujui retur pembelian'],
            ['permission_code' => 'purchasing.return.post', 'description' => 'Memposting retur pembelian dan mutasi stok keluar'],
            ['permission_code' => 'purchasing.debit_note.manage', 'description' => 'Menerbitkan dan mengaplikasikan nota debit pembelian ke faktur pemasok'],
        ];

        DB::table('permissions')->insertOrIgnore($permissions);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_debit_note_applications');
        Schema::dropIfExists('purchase_debit_notes');
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
    }
};
