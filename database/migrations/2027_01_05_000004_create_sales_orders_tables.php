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
        $driver = Schema::getConnection()->getDriverName();

        // 1. Tabel sales_orders
        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->string('so_number', 50);
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('quotation_id')->nullable();
                $table->string('customer_po_number', 100)->nullable();
                $table->unsignedBigInteger('billing_address_id')->nullable();
                $table->unsignedBigInteger('shipping_address_id')->nullable();
                $table->string('order_source', 30)->default('DIRECT_B2B');
                $table->string('channel_code', 40)->nullable();
                $table->string('external_order_id', 100)->nullable();
                $table->string('status', 30)->default('DRAFT');
                $table->string('fulfillment_status', 30)->default('UNFULFILLED');
                $table->string('invoicing_status', 30)->default('UNINVOICED');
                $table->date('order_date');
                $table->date('expected_delivery_date')->nullable();
                $table->unsignedInteger('payment_terms_days')->default(0);
                $table->decimal('subtotal', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('shipping_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->decimal('reserved_credit_amount', 18, 2)->default(0);
                $table->json('customer_snapshot_json')->nullable();
                $table->unsignedBigInteger('source_repeat_order_id')->nullable();
                $table->unsignedBigInteger('credit_override_approved_by')->nullable();
                $table->text('credit_override_reason')->nullable();
                $table->string('idempotency_key', 100)->nullable();
                $table->unsignedBigInteger('created_by_user_id');
                $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'id'], 'sales_orders_tenant_id_id_unique');
                $table->unique(['tenant_id', 'so_number'], 'sales_orders_tenant_so_number_unique');
                $table->unique(['tenant_id', 'idempotency_key'], 'sales_orders_tenant_idempotency_unique');
                $table->unique(['tenant_id', 'channel_code', 'external_order_id'], 'sales_orders_tenant_channel_ext_unique');

                $table->foreign(['tenant_id', 'store_id'], 'sales_orders_tenant_store_fk')
                    ->references(['tenant_id', 'id'])->on('stores')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'customer_id'], 'sales_orders_tenant_customer_fk')
                    ->references(['tenant_id', 'id'])->on('customers')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('quotation_id', 'sales_orders_quotation_fk')
                    ->references('id')->on('quotations')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('billing_address_id', 'sales_orders_billing_address_fk')
                    ->references('id')->on('customer_addresses')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('shipping_address_id', 'sales_orders_shipping_address_fk')
                    ->references('id')->on('customer_addresses')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('credit_override_approved_by', 'sales_orders_credit_override_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('created_by_user_id', 'sales_orders_created_by_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('confirmed_by_user_id', 'sales_orders_confirmed_by_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('cancelled_by_user_id', 'sales_orders_cancelled_by_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('source_repeat_order_id', 'sales_orders_repeat_order_fk')
                    ->references('id')->on('sales_orders')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'store_id', 'status'], 'sales_orders_tenant_store_status_idx');
                $table->index(['tenant_id', 'customer_id', 'status'], 'sales_orders_tenant_customer_status_idx');
                $table->index(['tenant_id', 'order_date'], 'sales_orders_tenant_order_date_idx');
                $table->index(['tenant_id', 'quotation_id'], 'sales_orders_tenant_quotation_idx');
                $table->index(['tenant_id', 'fulfillment_status'], 'sales_orders_tenant_fulfillment_idx');
                $table->index(['tenant_id', 'invoicing_status'], 'sales_orders_tenant_invoicing_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT chk_sales_order_status CHECK (status IN ('DRAFT', 'PENDING_CREDIT_APPROVAL', 'CONFIRMED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'CLOSED', 'CANCELLED'))");
                DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT chk_sales_order_fulfillment CHECK (fulfillment_status IN ('UNFULFILLED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'BACKORDERED'))");
                DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT chk_sales_order_invoicing CHECK (invoicing_status IN ('UNINVOICED', 'PARTIALLY_INVOICED', 'FULLY_INVOICED'))");
            }
        }

        // 2. Tabel sales_order_lines
        if (! Schema::hasTable('sales_order_lines')) {
            Schema::create('sales_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('sales_order_id');
                $table->unsignedInteger('line_number');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->unsignedBigInteger('uom_id')->nullable();
                $table->string('description', 255)->nullable();
                $table->decimal('ordered_qty', 18, 4);
                $table->decimal('reserved_qty', 18, 4)->default(0);
                $table->decimal('fulfilled_qty', 18, 4)->default(0);
                $table->decimal('cancelled_qty', 18, 4)->default(0);
                $table->decimal('invoiced_qty', 18, 4)->default(0);
                $table->decimal('returned_qty', 18, 4)->default(0);
                $table->decimal('base_unit_price', 18, 2);
                $table->decimal('unit_price', 18, 2);
                $table->unsignedBigInteger('price_list_id')->nullable();
                $table->string('pricing_source', 40)->nullable();
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('line_total', 18, 2);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'sales_order_lines_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'sales_order_id'], 'sol_tenant_so_composite_fk')
                    ->references(['tenant_id', 'id'])->on('sales_orders')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'product_id'], 'sol_tenant_product_composite_fk')
                    ->references(['tenant_id', 'id'])->on('products')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('variant_id', 'sol_variant_fk')
                    ->references('id')->on('product_variants')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('uom_id', 'sol_uom_fk')
                    ->references('id')->on('units_of_measure')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('price_list_id', 'sol_price_list_fk')
                    ->references('id')->on('price_lists')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'sales_order_id', 'line_number'], 'sol_tenant_so_line_idx');
                $table->index(['tenant_id', 'product_id', 'variant_id'], 'sol_tenant_prod_var_idx');
            });
        }

        // 3. Foreign key dari quotations.converted_sales_order_id ke sales_orders.id
        if (Schema::hasTable('quotations') && Schema::hasColumn('quotations', 'converted_sales_order_id')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->foreign('converted_sales_order_id', 'quotations_converted_so_fk')
                    ->references('id')->on('sales_orders')
                    ->onDelete('set null')->onUpdate('cascade');
            });
        }

        // 4. Tambah kolom sales_order_id pada tabel existing orders
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'sales_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_order_id')->nullable()->after('customer_id');
                $table->foreign('sales_order_id', 'orders_sales_order_fk')
                    ->references('id')->on('sales_orders')
                    ->onDelete('set null')->onUpdate('cascade');
                $table->index(['tenant_id', 'sales_order_id'], 'orders_tenant_sales_order_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'sales_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign('orders_sales_order_fk');
                $table->dropIndex('orders_tenant_sales_order_idx');
                $table->dropColumn('sales_order_id');
            });
        }

        if (Schema::hasTable('quotations') && Schema::hasColumn('quotations', 'converted_sales_order_id')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropForeign('quotations_converted_so_fk');
            });
        }

        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
