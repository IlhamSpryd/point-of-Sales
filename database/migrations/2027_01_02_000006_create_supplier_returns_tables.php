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

        // 1. supplier_returns header
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('return_number', 64);
            $table->string('idempotency_key', 128);
            $table->unsignedBigInteger('goods_receipt_id')->nullable();
            $table->string('supplier_name', 128)->nullable();
            $table->string('supplier_reference', 128)->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('returned_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Composite unique indexes for multi-tenant isolation and child composite FKs
            $table->unique(['tenant_id', 'id'], 'supplier_returns_tenant_id_id_unique');
            $table->unique(['tenant_id', 'store_id', 'return_number'], 'supplier_returns_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'supplier_returns_idempotency_unique');

            // Composite FK to stores
            $table->foreign(['tenant_id', 'store_id'], 'sr_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            // Optional FK to goods_receipts
            $table->foreign('goods_receipt_id', 'sr_receipt_fk')
                ->references('id')->on('goods_receipts')
                ->onDelete('set null')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE supplier_returns ADD CONSTRAINT chk_sr_status
                CHECK (status IN ('DRAFT', 'POSTED', 'CANCELLED', 'REVERSED'))");
        }

        // 2. supplier_return_items detail
        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_return_id');
            $table->string('item_type', 16); // 'product' | 'ingredient'
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('reason', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            // Composite foreign keys with tenant scoping
            $table->foreign(['tenant_id', 'supplier_return_id'], 'sri_return_composite_fk')
                ->references(['tenant_id', 'id'])->on('supplier_returns')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'sri_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'product_id'], 'sri_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'ingredient_id'], 'sri_ingredient_composite_fk')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE supplier_return_items ADD CONSTRAINT chk_sri_item_type
                CHECK (item_type IN ('product', 'ingredient'))");

            DB::statement('ALTER TABLE supplier_return_items ADD CONSTRAINT chk_sri_quantity_cost
                CHECK (quantity > 0 AND unit_cost >= 0)');

            // Hard database triggers for XOR Invariant INV-M4-02
            DB::unprepared("
                CREATE TRIGGER trg_supplier_return_items_xor_insert
                BEFORE INSERT ON supplier_return_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: supplier_return_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_supplier_return_items_xor_update
                BEFORE UPDATE ON supplier_return_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: supplier_return_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_return_items_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_return_items_xor_update');
        }

        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
    }
};
