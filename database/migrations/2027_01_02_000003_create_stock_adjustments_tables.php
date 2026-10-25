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

        // 1. stock_adjustments header
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('adjustment_number', 64);
            $table->string('idempotency_key', 128);
            $table->string('status', 32)->default('DRAFT');
            $table->string('reason_code', 32); // DAMAGED, EXPIRED, SHRINKAGE, PRODUCTION_WASTE, FOUND, OPENING_STOCK, OTHER
            $table->timestamp('adjusted_at');
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Composite unique indexes for multi-tenant isolation and child composite FKs
            $table->unique(['tenant_id', 'id'], 'stock_adjustments_tenant_id_id_unique');
            $table->unique(['tenant_id', 'store_id', 'adjustment_number'], 'stock_adjustments_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'stock_adjustments_idempotency_unique');

            // Composite FK to stores
            $table->foreign(['tenant_id', 'store_id'], 'sa_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT chk_sa_status
                CHECK (status IN ('DRAFT', 'POSTED', 'CANCELLED'))");

            DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT chk_sa_reason_code
                CHECK (reason_code IN ('DAMAGED', 'EXPIRED', 'SHRINKAGE', 'PRODUCTION_WASTE', 'FOUND', 'OPENING_STOCK', 'OTHER'))");
        }

        // 2. stock_adjustment_items detail
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->string('item_type', 16); // 'product' | 'ingredient'
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->string('direction', 8); // 'IN' | 'OUT'
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost_snapshot', 18, 4)->default(0);
            $table->string('reason_note', 255)->nullable();
            $table->timestamps();

            // Composite foreign keys with tenant scoping
            $table->foreign(['tenant_id', 'stock_adjustment_id'], 'sai_adjustment_composite_fk')
                ->references(['tenant_id', 'id'])->on('stock_adjustments')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'sai_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'product_id'], 'sai_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'ingredient_id'], 'sai_ingredient_composite_fk')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Check constraints for non-FK columns
            DB::statement("ALTER TABLE stock_adjustment_items ADD CONSTRAINT chk_sai_item_type
                CHECK (item_type IN ('product', 'ingredient'))");

            DB::statement("ALTER TABLE stock_adjustment_items ADD CONSTRAINT chk_sai_direction
                CHECK (direction IN ('IN', 'OUT'))");

            DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT chk_sai_quantity_cost
                CHECK (quantity > 0 AND unit_cost_snapshot >= 0)');

            // Hard database triggers for XOR Invariant INV-M4-02 (MariaDB does not permit FK columns in CHECK constraints)
            DB::unprepared("
                CREATE TRIGGER trg_stock_adjustment_items_xor_insert
                BEFORE INSERT ON stock_adjustment_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: stock_adjustment_items XOR invariant violated. Exactly one of product_id or ingredient_id must be set.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_stock_adjustment_items_xor_update
                BEFORE UPDATE ON stock_adjustment_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: stock_adjustment_items XOR invariant violated. Exactly one of product_id or ingredient_id must be set.';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_adjustment_items_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_adjustment_items_xor_update');
        }

        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
