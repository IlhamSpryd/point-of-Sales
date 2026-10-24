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

        // 1. stock_opnames header
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('opname_number', 64);
            $table->string('idempotency_key', 128);
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('snapshot_taken_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Composite unique indexes for multi-tenant isolation and child composite FKs
            $table->unique(['tenant_id', 'id'], 'stock_opnames_tenant_id_id_unique');
            $table->unique(['tenant_id', 'store_id', 'opname_number'], 'stock_opnames_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'stock_opnames_idempotency_unique');

            // Composite FK to stores
            $table->foreign(['tenant_id', 'store_id'], 'so_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE stock_opnames ADD CONSTRAINT chk_so_status
                CHECK (status IN ('DRAFT', 'COUNTING', 'PENDING_APPROVAL', 'APPROVED', 'CANCELLED'))");
        }

        // 2. stock_opname_items detail
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('stock_opname_id');
            $table->string('item_type', 16); // 'product' | 'ingredient'
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('snapshot_on_hand', 18, 4)->default(0);
            $table->unsignedBigInteger('snapshot_last_movement_id')->nullable();
            $table->decimal('counted_quantity', 18, 4)->nullable();
            $table->decimal('variance_quantity', 18, 4)->nullable();
            $table->string('drift_resolution_mode', 32)->default('NONE'); // NONE, RE_SNAPSHOT, REBASE_OVERRIDE
            $table->string('reason_code', 32)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            // Unique constraint: item tidak boleh duplikat dalam satu sesi opname
            $table->unique(['stock_opname_id', 'product_id'], 'so_item_product_unique');
            $table->unique(['stock_opname_id', 'ingredient_id'], 'so_item_ingredient_unique');

            // Composite foreign keys with tenant scoping
            $table->foreign(['tenant_id', 'stock_opname_id'], 'soi_opname_composite_fk')
                ->references(['tenant_id', 'id'])->on('stock_opnames')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'soi_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'product_id'], 'soi_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'ingredient_id'], 'soi_ingredient_composite_fk')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Check constraints for non-FK columns
            DB::statement("ALTER TABLE stock_opname_items ADD CONSTRAINT chk_soi_item_type
                CHECK (item_type IN ('product', 'ingredient'))");

            DB::statement("ALTER TABLE stock_opname_items ADD CONSTRAINT chk_soi_drift_mode
                CHECK (drift_resolution_mode IN ('NONE', 'RE_SNAPSHOT', 'REBASE_OVERRIDE'))");

            DB::statement('ALTER TABLE stock_opname_items ADD CONSTRAINT chk_soi_counted_quantity
                CHECK (counted_quantity IS NULL OR counted_quantity >= 0)');

            // Hard database triggers for XOR Invariant INV-M4-02 (MariaDB does not permit FK columns in CHECK constraints)
            DB::unprepared("
                CREATE TRIGGER trg_stock_opname_items_xor_insert
                BEFORE INSERT ON stock_opname_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: stock_opname_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_stock_opname_items_xor_update
                BEFORE UPDATE ON stock_opname_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: stock_opname_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_opname_items_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_opname_items_xor_update');
        }

        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
