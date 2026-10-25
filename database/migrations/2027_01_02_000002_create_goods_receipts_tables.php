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

        // 1. goods_receipts header
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('receipt_number', 64);
            $table->string('idempotency_key', 128);
            $table->string('supplier_reference', 128)->nullable();
            $table->string('purchase_order_reference', 128)->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('received_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Composite unique indexes for multi-tenant isolation and child composite FKs
            $table->unique(['tenant_id', 'id'], 'goods_receipts_tenant_id_id_unique');
            $table->unique(['tenant_id', 'store_id', 'receipt_number'], 'goods_receipts_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'goods_receipts_idempotency_unique');

            // Composite FK to stores
            $table->foreign(['tenant_id', 'store_id'], 'gr_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT chk_gr_status
                CHECK (status IN ('DRAFT', 'POSTED', 'CANCELLED', 'REVERSED'))");
        }

        // 2. goods_receipt_items detail
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('goods_receipt_id');
            $table->string('item_type', 16); // 'product' | 'ingredient'
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('quantity_received', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('batch_number', 64)->nullable(); // OD-02 Lot Receipt Annotation
            $table->date('expiry_date')->nullable(); // OD-02 Lot Receipt Annotation
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            // Composite foreign keys with tenant scoping
            $table->foreign(['tenant_id', 'goods_receipt_id'], 'gri_receipt_composite_fk')
                ->references(['tenant_id', 'id'])->on('goods_receipts')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'gri_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'product_id'], 'gri_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'ingredient_id'], 'gri_ingredient_composite_fk')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Check constraints for non-FK columns (supported natively without error 1901)
            DB::statement("ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_gri_item_type
                CHECK (item_type IN ('product', 'ingredient'))");

            DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_gri_quantity_cost
                CHECK (quantity_received > 0 AND unit_cost >= 0)');

            // Hard database triggers for XOR Invariant INV-M4-02 (MariaDB does not permit FK columns in CHECK constraints)
            DB::unprepared("
                CREATE TRIGGER trg_goods_receipt_items_xor_insert
                BEFORE INSERT ON goods_receipt_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: goods_receipt_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_goods_receipt_items_xor_update
                BEFORE UPDATE ON goods_receipt_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: goods_receipt_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_goods_receipt_items_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_goods_receipt_items_xor_update');
        }

        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
