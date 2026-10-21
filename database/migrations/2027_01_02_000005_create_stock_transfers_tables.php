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

        // 1. stock_transfers header
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('from_store_id');
            $table->unsignedBigInteger('to_store_id');
            $table->string('transfer_number', 64);
            $table->string('ship_idempotency_key', 128);
            $table->string('receive_idempotency_key', 128)->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('shipped_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Multi-tenant isolation & composite references
            $table->unique(['tenant_id', 'id'], 'stock_transfers_tenant_id_id_unique');
            $table->unique(['tenant_id', 'transfer_number'], 'stock_transfers_number_unique');
            $table->unique(['tenant_id', 'ship_idempotency_key'], 'stock_transfers_ship_idemp_unique');

            // Composite FKs to stores
            $table->foreign(['tenant_id', 'from_store_id'], 'st_from_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'to_store_id'], 'st_to_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT chk_st_status
                CHECK (status IN ('DRAFT', 'IN_TRANSIT', 'RECEIVED', 'CANCELLED'))");

            // Hard triggers for from_store_id <> to_store_id (MariaDB Error 1901 forbids FK columns in CHECK constraints)
            DB::unprepared("
                CREATE TRIGGER trg_stock_transfers_stores_insert
                BEFORE INSERT ON stock_transfers
                FOR EACH ROW
                BEGIN
                    IF NEW.from_store_id = NEW.to_store_id THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-06: from_store_id cannot be the same as to_store_id.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_stock_transfers_stores_update
                BEFORE UPDATE ON stock_transfers
                FOR EACH ROW
                BEGIN
                    IF NEW.from_store_id = NEW.to_store_id THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-06: from_store_id cannot be the same as to_store_id.';
                    END IF;
                END
            ");
        }

        // 2. stock_transfer_items detail
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('stock_transfer_id');
            $table->string('item_type', 16); // 'product' | 'ingredient'
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('quantity_requested', 18, 4);
            $table->decimal('quantity_shipped', 18, 4)->default(0);
            $table->decimal('quantity_received', 18, 4)->nullable();
            $table->decimal('discrepancy_quantity', 18, 4)->default(0);
            $table->string('discrepancy_resolution', 32)->default('NONE'); // NONE, RETURN_TO_SOURCE, WRITE_OFF_IN_TRANSIT
            $table->string('discrepancy_reason_code', 32)->nullable();
            $table->decimal('carrying_unit_cost', 18, 4)->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            // Unique item per transfer document
            $table->unique(['stock_transfer_id', 'product_id'], 'sti_product_unique');
            $table->unique(['stock_transfer_id', 'ingredient_id'], 'sti_ingredient_unique');

            // Composite FKs
            $table->foreign(['tenant_id', 'stock_transfer_id'], 'sti_transfer_composite_fk')
                ->references(['tenant_id', 'id'])->on('stock_transfers')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'product_id'], 'sti_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'ingredient_id'], 'sti_ingredient_composite_fk')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_item_type
                CHECK (item_type IN ('product', 'ingredient'))");

            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_qty_requested
                CHECK (quantity_requested > 0)');

            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_qty_shipped
                CHECK (quantity_shipped >= 0)');

            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_qty_received
                CHECK (quantity_received IS NULL OR quantity_received >= 0)');

            // Hard Anti-Over-Receipt Constraint (INV-M4-06 & OD-03)
            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_anti_over_receipt
                CHECK (quantity_received IS NULL OR quantity_received <= quantity_shipped)');

            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_discrepancy_qty
                CHECK (discrepancy_quantity >= 0)');

            DB::statement("ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_discrepancy_res
                CHECK (discrepancy_resolution IN ('NONE', 'RETURN_TO_SOURCE', 'WRITE_OFF_IN_TRANSIT'))");

            // Hard database triggers for XOR Invariant INV-M4-02
            DB::unprepared("
                CREATE TRIGGER trg_stock_transfer_items_xor_insert
                BEFORE INSERT ON stock_transfer_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: stock_transfer_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER trg_stock_transfer_items_xor_update
                BEFORE UPDATE ON stock_transfer_items
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.item_type = 'product' AND NEW.product_id IS NOT NULL AND NEW.ingredient_id IS NULL) OR
                        (NEW.item_type = 'ingredient' AND NEW.ingredient_id IS NOT NULL AND NEW.product_id IS NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'INV-M4-02: stock_transfer_items XOR invariant violated. Exactly one of product_id or ingredient_id must be populated matching item_type.';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_transfers_stores_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_transfers_stores_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_transfer_items_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_transfer_items_xor_update');
        }

        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
