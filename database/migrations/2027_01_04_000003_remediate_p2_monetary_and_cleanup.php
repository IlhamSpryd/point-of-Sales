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
     * Remediasi P2 (Monetary Precision, Ledger Triggers, Composite FKs & Index Optimization):
     * 1. DB-011: Standarisasi order_items.order_price & order_subtotal ke BIGINT UNSIGNED.
     * 2. DB-006: Hardening payments.amount CHECK constraint agar strictly positive (> 0).
     * 3. DB-005: Perbaiki predikat trg_loyalty_ledger_no_update & trg_ingredient_stock_movements_no_update.
     * 4. DB-019: Tambah FK employee_id_sequences(tenant_id) -> tenants(id) dan index expires_at_epoch pada provisioning_nonces.
     * 5. DB-020: Tambah FK loyalty_ledger(tenant_id, store_id) -> stores(tenant_id, id) ON DELETE SET NULL.
     * 6. DB-008 (Sisa): Konversi order_items.item_discount_id, parked_carts(table, discount), grn_reversal_of ke composite FK, dan drop redundant FK email_outbox.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. DB-011: Standarisasi order_items moneter ke BIGINT UNSIGNED
        if (Schema::hasTable('order_items')) {
            DB::statement('ALTER TABLE order_items MODIFY COLUMN order_price BIGINT UNSIGNED NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE order_items MODIFY COLUMN order_subtotal BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }

        // 2. DB-006: Hardening payments.amount CHECK (amount > 0)
        if ($driver !== 'sqlite' && Schema::hasTable('payments')) {
            $hasCheck = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "payments" AND CONSTRAINT_NAME = "chk_payments_amount_positive"'
            );
            if (! empty($hasCheck)) {
                DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_amount_positive');
            }
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_positive CHECK (amount > 0)');
        }

        // 3. DB-005: Perbaiki predikat trigger update ledger
        if ($driver !== 'sqlite') {
            // loyalty_ledger
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');
            DB::unprepared("
                CREATE TRIGGER trg_loyalty_ledger_no_update
                BEFORE UPDATE ON loyalty_ledger
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        OLD.id <=> NEW.id AND
                        OLD.tenant_id <=> NEW.tenant_id AND
                        OLD.customer_id <=> NEW.customer_id AND
                        OLD.order_id <=> NEW.order_id AND
                        OLD.type <=> NEW.type AND
                        OLD.points <=> NEW.points AND
                        OLD.balance_after <=> NEW.balance_after AND
                        OLD.reference <=> NEW.reference AND
                        OLD.prev_hash <=> NEW.prev_hash AND
                        OLD.entry_hash <=> NEW.entry_hash AND
                        OLD.created_by <=> NEW.created_by AND
                        OLD.created_at <=> NEW.created_at
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: UPDATE dilarang.';
                    END IF;
                END;
            ");

            // ingredient_stock_movements
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_update');
            DB::unprepared("
                CREATE TRIGGER trg_ingredient_stock_movements_no_update
                BEFORE UPDATE ON ingredient_stock_movements
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        OLD.id <=> NEW.id AND
                        OLD.tenant_id <=> NEW.tenant_id AND
                        OLD.ingredient_id <=> NEW.ingredient_id AND
                        OLD.order_id <=> NEW.order_id AND
                        OLD.order_item_id <=> NEW.order_item_id AND
                        OLD.type <=> NEW.type AND
                        OLD.quantity <=> NEW.quantity AND
                        OLD.unit_cost <=> NEW.unit_cost AND
                        OLD.reason <=> NEW.reason AND
                        OLD.idempotency_key <=> NEW.idempotency_key AND
                        OLD.created_by <=> NEW.created_by AND
                        OLD.created_at <=> NEW.created_at
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: UPDATE dilarang.';
                    END IF;
                END;
            ");
        }

        // 4. DB-019: employee_id_sequences FK & provisioning_nonces index
        if (Schema::hasTable('employee_id_sequences') && Schema::hasTable('tenants')) {
            $hasEisFk = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "employee_id_sequences" AND CONSTRAINT_NAME = "fk_eis_tenant"'
            );
            if (empty($hasEisFk)) {
                Schema::table('employee_id_sequences', function (Blueprint $table) {
                    $table->foreign('tenant_id', 'fk_eis_tenant')
                        ->references('id')->on('tenants')
                        ->onDelete('cascade')->onUpdate('cascade');
                });
            }
        }

        if (Schema::hasTable('provisioning_nonces')) {
            $hasNonceIdx = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "provisioning_nonces" AND INDEX_NAME = "idx_provisioning_nonces_expires"'
            );
            if (empty($hasNonceIdx)) {
                Schema::table('provisioning_nonces', function (Blueprint $table) {
                    $table->index('expires_at_epoch', 'idx_provisioning_nonces_expires');
                });
            }
        }

        // 5. DB-020: loyalty_ledger store composite FK
        if (Schema::hasTable('loyalty_ledger') && Schema::hasTable('stores')) {
            $hasLlFk = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "loyalty_ledger" AND CONSTRAINT_NAME = "fk_loyalty_ledger_store"'
            );
            if (empty($hasLlFk)) {
                Schema::table('loyalty_ledger', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'store_id'], 'fk_loyalty_ledger_store')
                        ->references(['tenant_id', 'id'])->on('stores')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }
        }

        // 6. DB-008 (Sisa): Relasi Komposit Lainnya
        // 6a. order_items -> discounts
        if (Schema::hasTable('order_items') && Schema::hasTable('discounts')) {
            $hasOld = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "order_items" AND CONSTRAINT_NAME = "order_items_item_discount_id_foreign"'
            );
            if (! empty($hasOld)) {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->dropForeign('order_items_item_discount_id_foreign');
                });
            }

            $hasNew = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "order_items" AND CONSTRAINT_NAME = "order_items_discount_composite_fk"'
            );
            if (empty($hasNew)) {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'item_discount_id'], 'order_items_discount_composite_fk')
                        ->references(['tenant_id', 'id'])->on('discounts')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }
        }

        // 6b. parked_carts -> tables
        if (Schema::hasTable('parked_carts') && Schema::hasTable('tables')) {
            $hasOld = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "parked_carts" AND CONSTRAINT_NAME = "fk_pc_table"'
            );
            if (! empty($hasOld)) {
                Schema::table('parked_carts', function (Blueprint $table) {
                    $table->dropForeign('fk_pc_table');
                });
            }

            $hasNew = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "parked_carts" AND CONSTRAINT_NAME = "fk_pc_table_composite"'
            );
            if (empty($hasNew)) {
                Schema::table('parked_carts', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'table_id'], 'fk_pc_table_composite')
                        ->references(['tenant_id', 'id'])->on('tables')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }
        }

        // 6c. parked_carts -> discounts
        if (Schema::hasTable('parked_carts') && Schema::hasTable('discounts')) {
            $hasOld = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "parked_carts" AND CONSTRAINT_NAME = "fk_pc_discount"'
            );
            if (! empty($hasOld)) {
                Schema::table('parked_carts', function (Blueprint $table) {
                    $table->dropForeign('fk_pc_discount');
                });
            }

            $hasNew = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "parked_carts" AND CONSTRAINT_NAME = "fk_pc_discount_composite"'
            );
            if (empty($hasNew)) {
                Schema::table('parked_carts', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'discount_id'], 'fk_pc_discount_composite')
                        ->references(['tenant_id', 'id'])->on('discounts')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }
        }

        // 6d. goods_received_notes -> goods_received_notes (reversal self-reference)
        if (Schema::hasTable('goods_received_notes')) {
            $hasOld = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "goods_received_notes" AND CONSTRAINT_NAME = "grn_reversal_of_fk"'
            );
            if (! empty($hasOld)) {
                Schema::table('goods_received_notes', function (Blueprint $table) {
                    $table->dropForeign('grn_reversal_of_fk');
                });
            }

            $hasNew = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "goods_received_notes" AND CONSTRAINT_NAME = "grn_reversal_of_composite_fk"'
            );
            if (empty($hasNew)) {
                Schema::table('goods_received_notes', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'reversal_of_grn_id'], 'grn_reversal_of_composite_fk')
                        ->references(['tenant_id', 'id'])->on('goods_received_notes')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }
        }

        // 6e. email_outbox -> drop redundant single-column FK
        if (Schema::hasTable('email_outbox')) {
            $hasRedundant = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "email_outbox" AND CONSTRAINT_NAME = "email_outbox_user_fk"'
            );
            if (! empty($hasRedundant)) {
                Schema::table('email_outbox', function (Blueprint $table) {
                    $table->dropForeign('email_outbox_user_fk');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        // 6. Rollback DB-008
        if (Schema::hasTable('email_outbox') && Schema::hasTable('users')) {
            Schema::table('email_outbox', function (Blueprint $table) {
                $table->foreign('user_id', 'email_outbox_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null');
            });
        }

        if (Schema::hasTable('goods_received_notes')) {
            Schema::table('goods_received_notes', function (Blueprint $table) {
                $table->dropForeign('grn_reversal_of_composite_fk');
                $table->foreign('reversal_of_grn_id', 'grn_reversal_of_fk')
                    ->references('id')->on('goods_received_notes')
                    ->onDelete('restrict');
            });
        }

        if (Schema::hasTable('parked_carts')) {
            Schema::table('parked_carts', function (Blueprint $table) {
                $table->dropForeign('fk_pc_discount_composite');
                $table->foreign('discount_id', 'fk_pc_discount')
                    ->references('id')->on('discounts')
                    ->onDelete('set null');

                $table->dropForeign('fk_pc_table_composite');
                $table->foreign('table_id', 'fk_pc_table')
                    ->references('id')->on('tables')
                    ->onDelete('set null');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropForeign('order_items_discount_composite_fk');
                $table->foreign('item_discount_id', 'order_items_item_discount_id_foreign')
                    ->references('id')->on('discounts')
                    ->onDelete('set null');
            });
        }

        // 5. Rollback DB-020
        if (Schema::hasTable('loyalty_ledger')) {
            Schema::table('loyalty_ledger', function (Blueprint $table) {
                $table->dropForeign('fk_loyalty_ledger_store');
            });
        }

        // 4. Rollback DB-019
        if (Schema::hasTable('provisioning_nonces')) {
            Schema::table('provisioning_nonces', function (Blueprint $table) {
                $table->dropIndex('idx_provisioning_nonces_expires');
            });
        }

        if (Schema::hasTable('employee_id_sequences')) {
            Schema::table('employee_id_sequences', function (Blueprint $table) {
                $table->dropForeign('fk_eis_tenant');
            });
        }

        // 3. Rollback DB-005
        if ($driver !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');
            DB::unprepared("
                CREATE TRIGGER trg_loyalty_ledger_no_update BEFORE UPDATE ON loyalty_ledger
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: UPDATE dilarang.';
                END;
            ");

            DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_update');
            DB::unprepared("
                CREATE TRIGGER trg_ingredient_stock_movements_no_update BEFORE UPDATE ON ingredient_stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: UPDATE dilarang.';
                END;
            ");
        }

        // 2. Rollback DB-006
        if ($driver !== 'sqlite' && Schema::hasTable('payments')) {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_amount_positive');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_positive CHECK (amount >= 0)');
        }

        // 1. Rollback DB-011
        if (Schema::hasTable('order_items')) {
            DB::statement('ALTER TABLE order_items MODIFY COLUMN order_price BIGINT NOT NULL');
            DB::statement('ALTER TABLE order_items MODIFY COLUMN order_subtotal BIGINT NOT NULL');
        }
    }
};
