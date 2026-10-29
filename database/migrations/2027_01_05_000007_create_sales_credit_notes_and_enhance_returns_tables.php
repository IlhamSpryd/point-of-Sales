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

        // 1. Perluas tabel order_returns dengan customer_id dan customer_invoice_id
        if (Schema::hasTable('order_returns')) {
            Schema::table('order_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('order_returns', 'customer_id')) {
                    $table->unsignedBigInteger('customer_id')->nullable()->after('order_id');
                }
                if (! Schema::hasColumn('order_returns', 'customer_invoice_id')) {
                    $table->unsignedBigInteger('customer_invoice_id')->nullable()->after('customer_id');
                }

                $table->index(['tenant_id', 'customer_id'], 'idx_order_returns_cust');
                $table->index(['tenant_id', 'customer_invoice_id'], 'idx_order_returns_cinv');

                $table->foreign(['tenant_id', 'customer_id'], 'fk_order_returns_customer')
                    ->references(['tenant_id', 'id'])
                    ->on('customers')
                    ->onUpdate('cascade')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'customer_invoice_id'], 'fk_order_returns_cinv')
                    ->references(['tenant_id', 'id'])
                    ->on('customer_invoices')
                    ->onUpdate('cascade')
                    ->onDelete('restrict');
            });
        }

        // 2. Tabel sales_credit_notes (Nota Kredit Penjualan)
        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('credit_note_number', 50);
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('order_return_id')->nullable();
            $table->unsignedBigInteger('origin_customer_invoice_id')->nullable();
            $table->unsignedBigInteger('origin_order_id')->nullable();
            $table->string('status', 30)->default('ISSUED'); // DRAFT, ISSUED, PARTIALLY_APPLIED, APPLIED, CONVERTED_TO_STORE_CREDIT, VOIDED
            $table->date('issue_date');
            $table->string('reason_code', 40)->default('RETURN'); // RETURN, PRICE_CORRECTION, DAMAGE, GOODWILL, OTHER
            $table->decimal('subtotal', 18, 2)->default(0.00);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2)->default(0.00);
            $table->decimal('applied_amount', 18, 2)->default(0.00);
            $table->decimal('remaining_amount', 18, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedBigInteger('issued_by_user_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'scn_tenant_id_unique');
            $table->unique(['tenant_id', 'store_id', 'id'], 'scn_tenant_store_id_unique');
            $table->unique(['tenant_id', 'credit_note_number'], 'scn_tenant_cn_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'scn_tenant_idempotency_unique');

            $table->index(['tenant_id', 'customer_id', 'status'], 'scn_tenant_cust_status_idx');
            $table->index(['tenant_id', 'store_id', 'status'], 'scn_tenant_store_status_idx');
            $table->index(['tenant_id', 'origin_customer_invoice_id'], 'scn_tenant_cinv_idx');

            $table->foreign(['tenant_id', 'store_id'], 'fk_sales_credit_notes_store')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'customer_id'], 'fk_sales_credit_notes_customer')
                ->references(['tenant_id', 'id'])
                ->on('customers')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'order_return_id'], 'fk_sales_credit_notes_return')
                ->references(['tenant_id', 'id'])
                ->on('order_returns')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'origin_customer_invoice_id'], 'fk_sales_credit_notes_cinv')
                ->references(['tenant_id', 'id'])
                ->on('customer_invoices')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'origin_order_id'], 'fk_sales_credit_notes_order')
                ->references(['tenant_id', 'id'])
                ->on('orders')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'issued_by_user_id'], 'fk_sales_credit_notes_issuer')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });

        // 3. Tabel sales_credit_note_applications (Alokasi Credit Note ke Customer Invoice)
        Schema::create('sales_credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sales_credit_note_id');
            $table->unsignedBigInteger('customer_invoice_id');
            $table->decimal('applied_amount', 18, 2);
            $table->unsignedBigInteger('applied_by_user_id')->nullable();
            $table->timestamp('applied_at');
            $table->string('idempotency_key', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'scna_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'scna_tenant_idempotency_unique');

            $table->index(['tenant_id', 'sales_credit_note_id'], 'scna_tenant_scn_idx');
            $table->index(['tenant_id', 'customer_invoice_id'], 'scna_tenant_cinv_idx');

            $table->foreign(['tenant_id', 'sales_credit_note_id'], 'fk_scna_credit_note')
                ->references(['tenant_id', 'id'])
                ->on('sales_credit_notes')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'customer_invoice_id'], 'fk_scna_invoice')
                ->references(['tenant_id', 'id'])
                ->on('customer_invoices')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'applied_by_user_id'], 'fk_scna_user')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_note_applications');
        Schema::dropIfExists('sales_credit_notes');

        if (Schema::hasTable('order_returns')) {
            Schema::table('order_returns', function (Blueprint $table) {
                if (Schema::hasColumn('order_returns', 'customer_invoice_id')) {
                    $table->dropForeign('fk_order_returns_cinv');
                    $table->dropIndex('idx_order_returns_cinv');
                    $table->dropColumn('customer_invoice_id');
                }
                if (Schema::hasColumn('order_returns', 'customer_id')) {
                    $table->dropForeign('fk_order_returns_customer');
                    $table->dropIndex('idx_order_returns_cust');
                    $table->dropColumn('customer_id');
                }
            });
        }
    }
};
