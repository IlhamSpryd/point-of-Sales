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

        // 1. Tabel purchase_orders
        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('supplier_id');
                $table->string('po_number', 64);
                $table->integer('revision_number')->default(0);
                $table->string('source_type', 20)->default('from_pr'); // 'from_pr', 'direct'
                $table->date('order_date');
                $table->date('expected_delivery_date')->nullable();
                $table->integer('payment_terms_days')->default(0);
                $table->string('currency_code', 3)->default('IDR');
                $table->decimal('exchange_rate', 12, 4)->default('1.0000');
                $table->decimal('subtotal_amount', 18, 2)->default('0.00');
                $table->decimal('discount_amount', 18, 2)->default('0.00');
                $table->decimal('tax_amount', 18, 2)->default('0.00');
                $table->decimal('additional_cost_estimate', 18, 2)->default('0.00');
                $table->decimal('total_amount', 18, 2)->default('0.00');
                $table->string('status', 32)->default('draft'); // 'draft', 'pending_approval', 'approved', 'released', 'amended', 'closed', 'cancelled'
                $table->string('receiving_status', 32)->default('not_received'); // 'not_received', 'partially_received', 'fully_received', 'over_received'
                $table->string('invoicing_status', 32)->default('not_invoiced'); // 'not_invoiced', 'partially_invoiced', 'fully_invoiced'
                $table->unsignedBigInteger('created_by_user_id');
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('released_by_user_id')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('idempotency_key', 64);
                $table->text('notes')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'id'], 'po_tenant_id_id_unique');
                $table->unique(['tenant_id', 'po_number'], 'po_tenant_po_number_unique');
                $table->unique(['tenant_id', 'idempotency_key'], 'po_tenant_idempotency_unique');

                $table->foreign(['tenant_id', 'store_id'], 'po_store_fk')
                    ->references(['tenant_id', 'id'])->on('stores')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'supplier_id'], 'po_supplier_fk')
                    ->references(['tenant_id', 'id'])->on('suppliers')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('created_by_user_id', 'po_creator_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('approved_by_user_id', 'po_approver_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('released_by_user_id', 'po_releaser_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'store_id', 'status', 'receiving_status'], 'po_tenant_store_status_idx');
                $table->index(['tenant_id', 'supplier_id', 'order_date'], 'po_tenant_supplier_date_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_po_status CHECK (status IN ('draft', 'pending_approval', 'approved', 'rejected', 'released', 'amended', 'closed', 'cancelled'))");
                DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_po_receiving_status CHECK (receiving_status IN ('not_received', 'partially_received', 'fully_received', 'over_received'))");
                DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_po_invoicing_status CHECK (invoicing_status IN ('not_invoiced', 'partially_invoiced', 'fully_invoiced'))");
                DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_po_source_type CHECK (source_type IN ('from_pr', 'direct'))");
                DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT chk_po_amounts CHECK (subtotal_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND additional_cost_estimate >= 0 AND total_amount >= 0)');
            }
        }

        // 2. Tabel purchase_order_lines
        if (! Schema::hasTable('purchase_order_lines')) {
            Schema::create('purchase_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('purchase_order_id');
                $table->string('purchasable_type', 32); // 'product', 'ingredient'
                $table->unsignedBigInteger('purchasable_id');
                $table->string('purchase_uom', 20);
                $table->decimal('uom_conversion_factor', 18, 4)->default('1.0000');
                $table->decimal('ordered_qty', 18, 4);
                $table->decimal('base_ordered_qty', 18, 4);
                $table->decimal('received_qty', 18, 4)->default('0.0000');
                $table->decimal('accepted_qty', 18, 4)->default('0.0000');
                $table->decimal('rejected_qty', 18, 4)->default('0.0000');
                $table->decimal('returned_qty', 18, 4)->default('0.0000');
                $table->decimal('invoiced_qty', 18, 4)->default('0.0000');
                $table->decimal('unit_price', 18, 4);
                $table->decimal('discount_pct', 8, 4)->default('0.0000');
                $table->decimal('discount_amount', 18, 2)->default('0.00');
                $table->decimal('tax_rate', 8, 4)->default('0.0000');
                $table->boolean('tax_inclusive')->default(false);
                $table->decimal('tax_amount', 18, 2)->default('0.00');
                $table->decimal('line_total', 18, 2)->default('0.00');
                $table->decimal('over_receipt_tolerance_pct', 8, 4)->default('0.0000');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'pol_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'purchase_order_id'], 'pol_po_fk')
                    ->references(['tenant_id', 'id'])->on('purchase_orders')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->index(['tenant_id', 'purchase_order_id'], 'pol_tenant_po_idx');
                $table->index(['tenant_id', 'purchasable_type', 'purchasable_id'], 'pol_tenant_item_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE purchase_order_lines ADD CONSTRAINT chk_pol_purchasable_type CHECK (purchasable_type IN ('product', 'ingredient'))");
                DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT chk_pol_qtys CHECK (ordered_qty > 0 AND base_ordered_qty > 0 AND uom_conversion_factor > 0 AND received_qty >= 0 AND accepted_qty >= 0 AND rejected_qty >= 0 AND returned_qty >= 0 AND invoiced_qty >= 0)');
                DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT chk_pol_financials CHECK (unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');
            }
        }

        // 3. Tabel purchase_order_line_pr_allocations
        if (! Schema::hasTable('purchase_order_line_pr_allocations')) {
            Schema::create('purchase_order_line_pr_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('purchase_order_line_id');
                $table->unsignedBigInteger('purchase_request_line_id');
                $table->decimal('allocated_qty', 18, 4);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['tenant_id', 'id'], 'pol_pr_alloc_tenant_id_unique');
                $table->unique(['tenant_id', 'purchase_order_line_id', 'purchase_request_line_id'], 'pol_pr_alloc_line_pair_unique');

                $table->foreign(['tenant_id', 'purchase_order_line_id'], 'pol_alloc_po_line_fk')
                    ->references(['tenant_id', 'id'])->on('purchase_order_lines')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'purchase_request_line_id'], 'pol_alloc_pr_line_fk')
                    ->references(['tenant_id', 'id'])->on('purchase_request_lines')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->index(['tenant_id', 'purchase_request_line_id'], 'pol_alloc_pr_line_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE purchase_order_line_pr_allocations ADD CONSTRAINT chk_pol_alloc_qty CHECK (allocated_qty > 0)');
            }
        }

        // 4. Tabel purchase_order_revisions
        if (! Schema::hasTable('purchase_order_revisions')) {
            Schema::create('purchase_order_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('purchase_order_id');
                $table->integer('revision_number');
                $table->unsignedBigInteger('amended_by_user_id');
                $table->timestamp('amended_at')->useCurrent();
                $table->text('reason');
                $table->json('snapshot_data');
                $table->decimal('total_amount_before', 18, 2);
                $table->decimal('total_amount_after', 18, 2);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['tenant_id', 'id'], 'po_rev_tenant_id_unique');
                $table->unique(['tenant_id', 'purchase_order_id', 'revision_number'], 'po_rev_tenant_po_rev_unique');

                $table->foreign(['tenant_id', 'purchase_order_id'], 'po_rev_po_fk')
                    ->references(['tenant_id', 'id'])->on('purchase_orders')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('amended_by_user_id', 'po_rev_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->index(['tenant_id', 'purchase_order_id'], 'po_rev_tenant_po_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE purchase_order_revisions ADD CONSTRAINT chk_po_rev_num CHECK (revision_number >= 1)');
            }
        }

        // 5. Seed Permissions Modul 5 untuk Purchase Order (PO)
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'purchasing.po.view', 'description' => 'Melihat daftar dan rincian Pesanan Pembelian (PO)'],
                ['permission_code' => 'purchasing.po.create', 'description' => 'Membuat Pesanan Pembelian (PO) dari PR yang disetujui'],
                ['permission_code' => 'purchasing.po.create_direct', 'description' => 'Membuat Pesanan Pembelian langsung (Direct PO) tanpa PR'],
                ['permission_code' => 'purchasing.po.update', 'description' => 'Mengubah draf Pesanan Pembelian (PO)'],
                ['permission_code' => 'purchasing.po.approve', 'description' => 'Menyetujui atau menolak Pesanan Pembelian (PO)'],
                ['permission_code' => 'purchasing.po.release', 'description' => 'Menerbitkan dan mengunci Pesanan Pembelian (PO) ke pemasok'],
                ['permission_code' => 'purchasing.po.amend', 'description' => 'Mengamandemen Pesanan Pembelian (PO) yang telah diterbitkan'],
                ['permission_code' => 'purchasing.po.cancel', 'description' => 'Membatalkan Pesanan Pembelian (PO)'],
                ['permission_code' => 'purchasing.po.close', 'description' => 'Menutup Pesanan Pembelian (PO) secara manual'],
                ['permission_code' => 'purchasing.po.view_prices', 'description' => 'Melihat informasi harga beli dan nominal pada pengadaan'],
            ];

            foreach ($permissions as $perm) {
                DB::table('permissions')->insertOrIgnore([
                    'permission_code' => $perm['permission_code'],
                    'description' => $perm['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_revisions');
        Schema::dropIfExists('purchase_order_line_pr_allocations');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
