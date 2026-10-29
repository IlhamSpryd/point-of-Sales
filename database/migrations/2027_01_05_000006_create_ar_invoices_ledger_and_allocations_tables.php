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

        // 1. Tabel customer_invoices (Header Faktur Penjualan / Piutang AR)
        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('invoice_number', 50);
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('customer_po_number', 100)->nullable();
            $table->string('status', 30)->default('DRAFT'); // DRAFT, POSTED, PARTIALLY_PAID, PAID, VOIDED
            $table->date('invoice_date');
            $table->date('due_date');
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('subtotal', 18, 2)->default(0.00);
            $table->decimal('discount_amount', 18, 2)->default(0.00);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('shipping_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2)->default(0.00);
            $table->decimal('paid_amount', 18, 2)->default(0.00);
            $table->decimal('credit_applied_amount', 18, 2)->default(0.00);
            $table->decimal('balance_due', 18, 2)->default(0.00);
            $table->json('customer_snapshot_json')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'cinv_tenant_id_id_unique');
            $table->unique(['tenant_id', 'invoice_number'], 'cinv_tenant_inv_num_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'cinv_tenant_idempotency_unique');

            $table->index(['tenant_id', 'customer_id', 'status', 'due_date'], 'cinv_tenant_cust_status_due_idx');
            $table->index(['tenant_id', 'store_id', 'status', 'due_date'], 'cinv_tenant_store_status_due_idx');
            $table->index(['tenant_id', 'sales_order_id'], 'cinv_tenant_so_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'cinv_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_id'], 'cinv_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'sales_order_id'], 'cinv_so_composite_fk')
                ->references(['tenant_id', 'id'])->on('sales_orders')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'order_id'], 'cinv_order_composite_fk')
                ->references(['tenant_id', 'id'])->on('orders')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('posted_by_user_id', 'cinv_posted_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('voided_by_user_id', 'cinv_voided_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE customer_invoices ADD CONSTRAINT chk_cinv_status
                CHECK (status IN ('DRAFT', 'POSTED', 'PARTIALLY_PAID', 'PAID', 'VOIDED'))");

            DB::statement('ALTER TABLE customer_invoices ADD CONSTRAINT chk_cinv_amounts
                CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND shipping_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0 AND credit_applied_amount >= 0 AND balance_due >= 0)');
        }

        // 2. Tabel customer_invoice_lines (Baris Faktur Penjualan)
        Schema::create('customer_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_invoice_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0.00);
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('line_total', 18, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'cinvl_tenant_id_id_unique');
            $table->unique(['customer_invoice_id', 'line_number'], 'cinvl_inv_line_unique');

            $table->index(['tenant_id', 'customer_invoice_id'], 'cinvl_tenant_inv_idx');
            $table->index(['tenant_id', 'sales_order_line_id'], 'cinvl_tenant_sol_idx');
            $table->index(['tenant_id', 'product_id'], 'cinvl_tenant_product_idx');

            $table->foreign(['tenant_id', 'customer_invoice_id'], 'cinvl_invoice_composite_fk')
                ->references(['tenant_id', 'id'])->on('customer_invoices')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'product_id'], 'cinvl_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'sales_order_line_id'], 'cinvl_sol_composite_fk')
                ->references(['tenant_id', 'id'])->on('sales_order_lines')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE customer_invoice_lines ADD CONSTRAINT chk_cinvl_qtys
                CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');
        }

        // 3. Tabel ar_ledger_entries (Append-Only Subledger Piutang Usaha)
        Schema::create('ar_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('customer_invoice_id')->nullable();
            $table->timestamp('entry_date');
            $table->string('entry_type', 40); // INVOICE_DEBIT, PAYMENT_CREDIT, CREDIT_NOTE, DEBIT_ADJUSTMENT, REVERSAL
            $table->string('reference_type', 60);
            $table->unsignedBigInteger('reference_id');
            $table->string('reference_number', 64)->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0.00);
            $table->decimal('credit_amount', 18, 2)->default(0.00);
            $table->decimal('running_balance_snapshot', 18, 2)->default(0.00);
            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'arle_tenant_id_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'arle_tenant_idempotency_unique');

            $table->index(['tenant_id', 'customer_id', 'entry_date', 'id'], 'arle_tenant_cust_date_id_idx');
            $table->index(['tenant_id', 'customer_invoice_id'], 'arle_tenant_cinv_idx');
            $table->index(['tenant_id', 'reference_type', 'reference_id'], 'arle_tenant_ref_idx');

            $table->foreign(['tenant_id', 'store_id'], 'arle_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_id'], 'arle_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_invoice_id'], 'arle_cinv_composite_fk')
                ->references(['tenant_id', 'id'])->on('customer_invoices')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('actor_user_id', 'arle_actor_user_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE ar_ledger_entries ADD CONSTRAINT chk_arle_entry_type
                CHECK (entry_type IN ('INVOICE_DEBIT', 'PAYMENT_CREDIT', 'CREDIT_NOTE', 'DEBIT_ADJUSTMENT', 'REVERSAL'))");

            DB::statement('ALTER TABLE ar_ledger_entries ADD CONSTRAINT chk_arle_amounts
                CHECK (debit_amount >= 0 AND credit_amount >= 0)');
        }

        // 4. Tabel ar_payment_allocations (Alokasi Pembayaran ke Invoice Tertentu)
        Schema::create('ar_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('customer_invoice_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->timestamp('allocated_at');
            $table->unsignedBigInteger('allocated_by_user_id');
            $table->boolean('is_reversed')->default(false);
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'arpa_tenant_id_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'arpa_tenant_idempotency_unique');

            $table->index(['tenant_id', 'customer_invoice_id'], 'arpa_tenant_cinv_idx');
            $table->index(['tenant_id', 'customer_id'], 'arpa_tenant_cust_idx');
            $table->index(['tenant_id', 'payment_id'], 'arpa_tenant_payment_idx');

            $table->foreign(['tenant_id', 'customer_id'], 'arpa_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_invoice_id'], 'arpa_cinv_composite_fk')
                ->references(['tenant_id', 'id'])->on('customer_invoices')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('allocated_by_user_id', 'arpa_allocator_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('reversed_by_user_id', 'arpa_reverser_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ar_payment_allocations ADD CONSTRAINT chk_arpa_amount
                CHECK (allocated_amount > 0)');
        }

        // 5. Tabel accounting_receivable_outboxes (Outbox Integrasi Jurnal AR ke M7)
        Schema::create('accounting_receivable_outboxes', function (Blueprint $table) {
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

            $table->unique(['tenant_id', 'id'], 'ar_outbox_tenant_id_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'ar_outbox_tenant_idempotency_unique');

            $table->index(['tenant_id', 'status', 'created_at'], 'ar_outbox_tenant_status_created_idx');
            $table->index(['tenant_id', 'event_type', 'status'], 'ar_outbox_tenant_event_status_idx');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE accounting_receivable_outboxes ADD CONSTRAINT chk_ar_outbox_status
                CHECK (status IN ('pending', 'processed_by_m7', 'failed'))");
        }

        // 6. Seed Permissions AR di tabel permissions (jika ada)
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'ar.view', 'description' => 'Melihat daftar faktur piutang, buku besar AR, dan aging piutang'],
                ['permission_code' => 'ar.post_invoice', 'description' => 'Menerbitkan dan memposting faktur piutang (Customer Invoice)'],
                ['permission_code' => 'ar.allocate_payment', 'description' => 'Menerima dan mengalokasikan pembayaran piutang ke faktur'],
                ['permission_code' => 'ar.reverse_allocation', 'description' => 'Membatalkan (reverse) alokasi pembayaran piutang'],
                ['permission_code' => 'ar.export_statement', 'description' => 'Mengekspor rekening koran piutang (Customer Statement)'],
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
        Schema::dropIfExists('accounting_receivable_outboxes');
        Schema::dropIfExists('ar_payment_allocations');
        Schema::dropIfExists('ar_ledger_entries');
        Schema::dropIfExists('customer_invoice_lines');
        Schema::dropIfExists('customer_invoices');
    }
};
