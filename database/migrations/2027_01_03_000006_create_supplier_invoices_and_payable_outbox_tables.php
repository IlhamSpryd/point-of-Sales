<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Tabel supplier_invoices (Header Faktur Pemasok)
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('internal_invoice_number', 64);
            $table->string('supplier_invoice_number', 100);
            $table->string('normalized_supplier_invoice_number', 100);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1.000000);
            $table->decimal('subtotal_amount', 18, 2)->default(0.00);
            $table->decimal('discount_amount', 18, 2)->default(0.00);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('additional_charges_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2)->default(0.00);
            $table->decimal('debit_note_applied_amount', 18, 2)->default(0.00);
            $table->decimal('outstanding_amount', 18, 2)->default(0.00);
            $table->string('status', 32)->default('draft');
            $table->string('matching_status', 32)->default('unmatched');
            $table->string('settlement_status', 32)->default('unpaid');
            $table->timestamp('approved_payable_at')->nullable();
            $table->unsignedBigInteger('approved_payable_by_user_id')->nullable();
            $table->text('hold_reason')->nullable();
            $table->unsignedBigInteger('hold_approved_by_user_id')->nullable();
            $table->timestamp('hold_approved_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'sinv_tenant_id_id_unique');
            $table->unique(['tenant_id', 'internal_invoice_number'], 'sinv_tenant_internal_unique');
            $table->unique(['tenant_id', 'supplier_id', 'normalized_supplier_invoice_number'], 'sinv_tenant_supp_norm_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'sinv_tenant_idempotency_unique');

            $table->index(['tenant_id', 'supplier_id', 'status', 'due_date'], 'sinv_tenant_supp_status_due_idx');
            $table->index(['tenant_id', 'store_id', 'invoice_date'], 'sinv_tenant_store_date_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'sinv_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'supplier_id'], 'sinv_supplier_composite_fk')
                ->references(['tenant_id', 'id'])->on('suppliers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'purchase_order_id'], 'sinv_po_composite_fk')
                ->references(['tenant_id', 'id'])->on('purchase_orders')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('approved_payable_by_user_id', 'sinv_approved_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('hold_approved_by_user_id', 'sinv_hold_approved_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT chk_sinv_status
                CHECK (status IN ('draft', 'submitted', 'variance_hold', 'approved_payable', 'disputed', 'cancelled'))");

            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT chk_sinv_matching
                CHECK (matching_status IN ('unmatched', 'partially_matched', 'matched', 'variance_detected'))");

            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT chk_sinv_settlement
                CHECK (settlement_status IN ('unpaid', 'partially_paid', 'paid'))");

            DB::statement('ALTER TABLE supplier_invoices ADD CONSTRAINT chk_sinv_amounts
                CHECK (subtotal_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND additional_charges_amount >= 0 AND total_amount >= 0 AND outstanding_amount >= 0)');
        }

        // 2. Tabel supplier_invoice_lines (Baris Faktur Pemasok)
        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedInteger('line_number');
            $table->string('purchasable_type', 32);
            $table->unsignedBigInteger('purchasable_id');
            $table->decimal('invoiced_qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 2)->default(0.00);
            $table->decimal('tax_rate', 8, 4)->default(0.0000);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('line_total', 18, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sinvl_tenant_id_id_unique');
            $table->unique(['supplier_invoice_id', 'line_number'], 'sinvl_invoice_line_unique');

            $table->index(['tenant_id', 'supplier_invoice_id'], 'sinvl_tenant_inv_idx');
            $table->index(['tenant_id', 'purchase_order_line_id'], 'sinvl_tenant_pol_idx');
            $table->index(['tenant_id', 'purchasable_type', 'purchasable_id'], 'sinvl_tenant_item_idx');

            $table->foreign(['tenant_id', 'supplier_invoice_id'], 'sinvl_invoice_composite_fk')
                ->references(['tenant_id', 'id'])->on('supplier_invoices')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'purchase_order_line_id'], 'sinvl_pol_composite_fk')
                ->references(['tenant_id', 'id'])->on('purchase_order_lines')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE supplier_invoice_lines ADD CONSTRAINT chk_sinvl_qtys
                CHECK (invoiced_qty > 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');
        }

        // 3. Tabel supplier_invoice_grn_matches (Pencocokan 3-Way Match ke GRN)
        Schema::create('supplier_invoice_grn_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->unsignedBigInteger('supplier_invoice_line_id');
            $table->unsignedBigInteger('goods_received_note_line_id');
            $table->decimal('matched_qty', 18, 4);
            $table->decimal('po_unit_price', 18, 4);
            $table->decimal('invoice_unit_price', 18, 4);
            $table->decimal('qty_variance', 18, 4)->default(0.0000);
            $table->decimal('price_variance_amount', 18, 2)->default(0.00);
            $table->decimal('tax_variance_amount', 18, 2)->default(0.00);
            $table->string('variance_status', 32)->default('none');
            $table->boolean('is_variance_approved')->default(false);
            $table->unsignedBigInteger('variance_approved_by_user_id')->nullable();
            $table->text('variance_approval_notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sigm_tenant_id_id_unique');
            $table->unique(['supplier_invoice_line_id', 'goods_received_note_line_id'], 'sigm_inv_grn_line_unique');

            $table->foreign(['tenant_id', 'supplier_invoice_id'], 'sigm_invoice_composite_fk')
                ->references(['tenant_id', 'id'])->on('supplier_invoices')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'supplier_invoice_line_id'], 'sigm_line_composite_fk')
                ->references(['tenant_id', 'id'])->on('supplier_invoice_lines')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'goods_received_note_line_id'], 'sigm_grnl_composite_fk')
                ->references(['tenant_id', 'id'])->on('goods_received_note_lines')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('variance_approved_by_user_id', 'sigm_var_approver_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE supplier_invoice_grn_matches ADD CONSTRAINT chk_sigm_matched_qty
                CHECK (matched_qty > 0)');
        }

        // 4. Tabel accounting_payable_outbox (Integration Outbox Menuju M7)
        Schema::create('accounting_payable_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('event_type', 64);
            $table->string('idempotency_key', 128);
            $table->string('source_document_type', 64);
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_document_number', 64);
            $table->string('status', 32)->default('pending');
            $table->json('payload');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'apo_tenant_id_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'apo_tenant_idempotency_unique');

            $table->index(['tenant_id', 'status', 'created_at'], 'apo_tenant_status_created_idx');
            $table->index(['tenant_id', 'event_type', 'status'], 'apo_tenant_event_status_idx');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE accounting_payable_outbox ADD CONSTRAINT chk_apo_status
                CHECK (status IN ('pending', 'processed_by_m7', 'failed'))");
        }

        // 5. Seed Permissions Modul 5 untuk Faktur Pemasok & 3-Way Matching
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'purchasing.invoice.view', 'description' => 'Melihat daftar dan rincian Faktur Pemasok'],
                ['permission_code' => 'purchasing.invoice.create', 'description' => 'Mencatat Faktur Pemasok baru'],
                ['permission_code' => 'purchasing.invoice.match', 'description' => 'Mengeksekusi rekonsiliasi dan pencocokan 3-Way Matching'],
                ['permission_code' => 'purchasing.invoice.approve_variance', 'description' => 'Menyetujui selisih harga atau kuantitas pada 3-Way Match'],
                ['permission_code' => 'purchasing.invoice.approve_payable', 'description' => 'Menyetujui faktur pemasok menjadi kewajiban siap bayar (Approved Payable)'],
                ['permission_code' => 'purchasing.invoice.cancel', 'description' => 'Membatalkan Faktur Pemasok'],
            ];

            foreach ($permissions as $p) {
                DB::table('permissions')->insertOrIgnore([
                    'permission_code' => $p['permission_code'],
                    'description' => $p['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_payable_outbox');
        Schema::dropIfExists('supplier_invoice_grn_matches');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
    }
};
