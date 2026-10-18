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
        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

        // 0. Update check constraint pada cash_drawer_movements agar mendukung category 'refund'
        if ($isMysql) {
            try {
                DB::statement('ALTER TABLE cash_drawer_movements DROP CONSTRAINT chk_cdm_category_enum');
            } catch (Throwable) {
                try {
                    DB::statement('ALTER TABLE cash_drawer_movements DROP CHECK chk_cdm_category_enum');
                } catch (Throwable) {
                }
            }
            DB::statement("ALTER TABLE cash_drawer_movements ADD CONSTRAINT chk_cdm_category_enum CHECK (category IN ('restock_change','petty_expense','owner_withdrawal','bank_deposit','correction','refund'))");
        }

        // 1. Tabel order_returns
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('device_id');
            $table->string('return_number', 64);
            $table->string('idempotency_key', 100);
            $table->string('status', 24);
            $table->string('return_type', 16);
            $table->decimal('subtotal_returned', 15, 2);
            $table->decimal('discount_reversed', 15, 2)->default(0.00);
            $table->decimal('tax_returned', 15, 2)->default(0.00);
            $table->decimal('service_charge_returned', 15, 2)->default(0.00);
            $table->decimal('total_refundable_amount', 15, 2);
            $table->text('reason');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_order_returns_tenant_id');
            $table->unique(['tenant_id', 'store_id', 'id'], 'uq_order_returns_tenant_store_id');
            $table->unique(['tenant_id', 'store_id', 'return_number'], 'uq_order_returns_number');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_order_returns_idempotency');

            $table->index(['tenant_id', 'store_id']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'shift_id']);
            $table->index(['tenant_id', 'device_id']);
            $table->index(['tenant_id', 'status']);

            $table->foreign(['tenant_id', 'store_id'], 'fk_order_returns_store')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'order_id'], 'fk_order_returns_order')
                ->references(['tenant_id', 'id'])
                ->on('orders')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'fk_order_returns_shift')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('shifts')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'fk_order_returns_device')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('devices')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'requested_by_user_id'], 'fk_order_returns_requested_by')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'approved_by_user_id'], 'fk_order_returns_approved_by')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });

        if ($isMysql) {
            DB::statement("ALTER TABLE order_returns ADD CONSTRAINT chk_order_returns_status CHECK (status IN ('pending_approval', 'approved', 'completed', 'rejected', 'cancelled'))");
            DB::statement("ALTER TABLE order_returns ADD CONSTRAINT chk_order_returns_type CHECK (return_type IN ('full', 'partial'))");
        }

        // 2. Tabel order_return_items
        Schema::create('order_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_return_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity_returned');
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('line_discount_reversed', 15, 2)->default(0.00);
            $table->decimal('line_writeoff_or_refund_amount', 15, 2);
            $table->string('disposition', 24);
            $table->string('disposition_reason', 255)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_order_return_items_tenant_id');
            $table->index(['tenant_id', 'order_return_id']);
            $table->index(['tenant_id', 'order_item_id']);
            $table->index(['tenant_id', 'product_id']);

            $table->foreign(['tenant_id', 'store_id', 'order_return_id'], 'fk_order_return_items_return')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('order_returns')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'order_item_id'], 'fk_order_return_items_item')
                ->references(['tenant_id', 'id'])
                ->on('order_items')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'product_id'], 'fk_order_return_items_product')
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'stock_movement_id'], 'fk_order_return_items_movement')
                ->references(['tenant_id', 'id'])
                ->on('stock_movements')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });

        if ($isMysql) {
            DB::statement('ALTER TABLE order_return_items ADD CONSTRAINT chk_ori_quantity_returned CHECK (quantity_returned > 0)');
            DB::statement("ALTER TABLE order_return_items ADD CONSTRAINT chk_ori_disposition CHECK (disposition IN ('restock', 'quarantine', 'write_off_waste'))");
        }

        // 3. Tabel payment_refunds
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('order_return_id')->nullable();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('device_id');
            $table->string('idempotency_key', 100);
            $table->string('refund_number', 64);
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('IDR');
            $table->string('original_payment_method', 32);
            $table->string('refund_method', 32);
            $table->tinyInteger('is_method_override')->default(0);
            $table->text('method_override_reason')->nullable();
            $table->string('request_status', 24);
            $table->string('provider_status', 32)->default('not_applicable');
            $table->string('financial_status', 24)->default('unsettled');
            $table->string('provider_refund_reference', 128)->nullable();
            $table->unsignedBigInteger('cash_drawer_movement_id')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_payment_refunds_tenant_id');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_payment_refunds_idempotency');
            $table->unique(['tenant_id', 'store_id', 'refund_number'], 'uq_payment_refunds_number');

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'order_return_id']);
            $table->index(['tenant_id', 'shift_id']);
            $table->index(['tenant_id', 'device_id']);
            $table->index(['tenant_id', 'request_status']);
            $table->index(['tenant_id', 'financial_status']);

            $table->foreign(['tenant_id', 'store_id'], 'fk_payment_refunds_store')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'order_id'], 'fk_payment_refunds_order')
                ->references(['tenant_id', 'id'])
                ->on('orders')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'payment_id'], 'fk_payment_refunds_payment')
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'store_id', 'order_return_id'], 'fk_payment_refunds_return')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('order_returns')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'fk_payment_refunds_shift')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('shifts')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'fk_payment_refunds_device')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('devices')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'cash_drawer_movement_id'], 'fk_payment_refunds_drawer')
                ->references(['tenant_id', 'id'])
                ->on('cash_drawer_movements')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'requested_by_user_id'], 'fk_payment_refunds_requested_by')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'approved_by_user_id'], 'fk_payment_refunds_approved_by')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });

        if ($isMysql) {
            DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT chk_pr_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT chk_pr_request_status CHECK (request_status IN ('requested', 'approved', 'processing', 'completed', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT chk_pr_provider_status CHECK (provider_status IN ('not_applicable', 'pending', 'unknown_timeout', 'succeeded', 'rejected'))");
            DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT chk_pr_financial_status CHECK (financial_status IN ('unsettled', 'settled', 'reversed'))");

            // Immutability triggers untuk payment_refunds
            DB::unprepared("
                CREATE TRIGGER trg_payment_refunds_no_delete
                BEFORE DELETE ON payment_refunds
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'payment_refunds bersifat immutable: DELETE dilarang.';
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_payment_refunds_immutable_update
                BEFORE UPDATE ON payment_refunds
                FOR EACH ROW
                BEGIN
                    IF OLD.financial_status = 'settled' AND (
                        NEW.amount <> OLD.amount OR
                        NEW.payment_id <> OLD.payment_id OR
                        NEW.order_id <> OLD.order_id OR
                        NEW.tenant_id <> OLD.tenant_id OR
                        NEW.store_id <> OLD.store_id
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'payment_refunds yang sudah settled tidak boleh diubah nilai amount, payment_id, atau order_id.';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

        if ($isMysql) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_refunds_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_refunds_no_delete');
        }

        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
