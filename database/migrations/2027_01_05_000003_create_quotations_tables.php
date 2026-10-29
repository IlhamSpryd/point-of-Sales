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

        // 1. Tabel quotations
        if (! Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->string('quotation_number', 50);
                $table->unsignedInteger('version_number')->default(1);
                $table->unsignedBigInteger('parent_quotation_id')->nullable();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('customer_address_id')->nullable();
                $table->unsignedBigInteger('sales_rep_user_id')->nullable();
                $table->string('status', 30)->default('DRAFT');
                $table->date('issue_date');
                $table->date('valid_until');
                $table->char('currency_code', 3)->default('IDR');
                $table->unsignedInteger('payment_terms_days')->default(0);
                $table->decimal('subtotal', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->json('customer_snapshot_json')->nullable();
                $table->text('notes')->nullable();
                $table->text('terms_and_conditions')->nullable();
                $table->boolean('requires_approval')->default(false);
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejected_reason')->nullable();
                $table->unsignedBigInteger('converted_sales_order_id')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->string('idempotency_key', 100)->nullable();
                $table->unsignedBigInteger('created_by_user_id');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'id'], 'quotations_tenant_id_id_unique');
                $table->unique(['tenant_id', 'quotation_number', 'version_number'], 'quotations_tenant_number_version_unique');
                $table->unique(['tenant_id', 'idempotency_key'], 'quotations_tenant_idempotency_unique');

                $table->foreign(['tenant_id', 'store_id'], 'quotations_tenant_store_fk')
                    ->references(['tenant_id', 'id'])->on('stores')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'customer_id'], 'quotations_tenant_customer_fk')
                    ->references(['tenant_id', 'id'])->on('customers')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('customer_address_id', 'quotations_customer_address_fk')
                    ->references('id')->on('customer_addresses')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('sales_rep_user_id', 'quotations_sales_rep_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('approved_by_user_id', 'quotations_approved_by_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('created_by_user_id', 'quotations_created_by_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('parent_quotation_id', 'quotations_parent_fk')
                    ->references('id')->on('quotations')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'store_id', 'status'], 'quotations_tenant_store_status_idx');
                $table->index(['tenant_id', 'customer_id', 'status'], 'quotations_tenant_customer_status_idx');
                $table->index(['tenant_id', 'valid_until'], 'quotations_tenant_valid_until_idx');
                $table->index(['tenant_id', 'converted_sales_order_id'], 'quotations_tenant_converted_so_idx');
                $table->index(['tenant_id', 'sales_rep_user_id'], 'quotations_tenant_sales_rep_idx');
                $table->index(['tenant_id', 'parent_quotation_id'], 'quotations_tenant_parent_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE quotations ADD CONSTRAINT chk_quotations_status CHECK (status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'SENT', 'ACCEPTED', 'CONVERTED', 'REJECTED', 'EXPIRED', 'CANCELLED', 'SUPERSEDED'))");
            }
        }

        // 2. Tabel quotation_lines
        if (! Schema::hasTable('quotation_lines')) {
            Schema::create('quotation_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('quotation_id');
                $table->unsignedInteger('line_number');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->unsignedBigInteger('uom_id')->nullable();
                $table->string('description', 255)->nullable();
                $table->decimal('quantity', 18, 4);
                $table->decimal('base_unit_price', 18, 2);
                $table->decimal('unit_price', 18, 2);
                $table->unsignedBigInteger('price_list_id')->nullable();
                $table->string('pricing_source', 40)->nullable();
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->unsignedBigInteger('tax_class_id')->nullable();
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('line_total', 18, 2);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'quotation_lines_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'quotation_id'], 'ql_tenant_quotation_composite_fk')
                    ->references(['tenant_id', 'id'])->on('quotations')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'product_id'], 'ql_tenant_product_composite_fk')
                    ->references(['tenant_id', 'id'])->on('products')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('variant_id', 'ql_variant_fk')
                    ->references('id')->on('product_variants')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('uom_id', 'ql_uom_fk')
                    ->references('id')->on('units_of_measure')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('price_list_id', 'ql_price_list_fk')
                    ->references('id')->on('price_lists')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('tax_class_id', 'ql_tax_class_fk')
                    ->references('id')->on('tax_classes')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'quotation_id', 'line_number'], 'ql_tenant_quotation_line_idx');
                $table->index(['tenant_id', 'product_id', 'variant_id'], 'ql_tenant_prod_var_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
