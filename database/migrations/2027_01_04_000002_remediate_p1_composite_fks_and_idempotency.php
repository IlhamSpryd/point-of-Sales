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
     * Remediasi P1 (Integritas Relasi Finansial & Konkurensi):
     * 1. DB-007: Composite FK discounts(tenant_id, store_id) -> stores(tenant_id, id).
     * 2. DB-009: UNIQUE(tenant_id, store_id, id) pada orders + FK 3-kolom dari order_items, payments, stock_movements.
     * 3. DB-013: Unique return item per return + trigger guard over-refund pada payment_refunds.
     * 4. DB-015: Rescope idempotency key ke (tenant_id, idempotency_key) pada payments, stock_movements, ingredient_stock_movements, cash_drawer_movements.
     * 5. DB-008: Konversi single-column FKs ke composite FK (tenant_id, ...).
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. DB-007: FK komposit discounts -> stores
        if (Schema::hasTable('discounts') && Schema::hasTable('stores')) {
            $hasFk = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "discounts" AND CONSTRAINT_NAME = "fk_discounts_tenant_store"'
            );
            if (empty($hasFk)) {
                Schema::table('discounts', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'store_id'], 'fk_discounts_tenant_store')
                        ->references(['tenant_id', 'id'])->on('stores')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }
        }

        // 2. DB-009: orders UNIQUE(tenant_id, store_id, id) + FK 3-kolom ke detail/payment/stock
        if (Schema::hasTable('orders')) {
            $hasStoreIdUnique = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "orders" AND INDEX_NAME = "orders_tenant_store_id_unique"'
            );
            if (empty($hasStoreIdUnique)) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'store_id', 'id'], 'orders_tenant_store_id_unique');
                });
            }

            // Upgrade order_items ke FK 3-kolom
            if (Schema::hasTable('order_items')) {
                $hasOldFk = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "order_items" AND CONSTRAINT_NAME = "order_items_order_id_composite_foreign"'
                );
                if (! empty($hasOldFk)) {
                    Schema::table('order_items', function (Blueprint $table) {
                        $table->dropForeign('order_items_order_id_composite_foreign');
                    });
                }

                $has3ColFk = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "order_items" AND CONSTRAINT_NAME = "order_items_tenant_store_order_composite_fk"'
                );
                if (empty($has3ColFk)) {
                    Schema::table('order_items', function (Blueprint $table) {
                        $table->foreign(['tenant_id', 'store_id', 'order_id'], 'order_items_tenant_store_order_composite_fk')
                            ->references(['tenant_id', 'store_id', 'id'])->on('orders')
                            ->onDelete('restrict')->onUpdate('cascade');
                    });
                }
            }

            // Upgrade payments ke FK 3-kolom
            if (Schema::hasTable('payments')) {
                $hasOldFk = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "payments" AND CONSTRAINT_NAME = "payments_order_id_composite_foreign"'
                );
                if (! empty($hasOldFk)) {
                    Schema::table('payments', function (Blueprint $table) {
                        $table->dropForeign('payments_order_id_composite_foreign');
                    });
                }

                $has3ColFk = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "payments" AND CONSTRAINT_NAME = "payments_tenant_store_order_composite_fk"'
                );
                if (empty($has3ColFk)) {
                    Schema::table('payments', function (Blueprint $table) {
                        $table->foreign(['tenant_id', 'store_id', 'order_id'], 'payments_tenant_store_order_composite_fk')
                            ->references(['tenant_id', 'store_id', 'id'])->on('orders')
                            ->onDelete('restrict')->onUpdate('cascade');
                    });
                }
            }

            // Upgrade stock_movements ke FK 3-kolom
            if (Schema::hasTable('stock_movements')) {
                $hasOldFk = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "stock_movements" AND CONSTRAINT_NAME = "stock_movements_order_id_composite_foreign"'
                );
                if (! empty($hasOldFk)) {
                    Schema::table('stock_movements', function (Blueprint $table) {
                        $table->dropForeign('stock_movements_order_id_composite_foreign');
                    });
                }

                $has3ColFk = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "stock_movements" AND CONSTRAINT_NAME = "stock_movements_tenant_store_order_composite_fk"'
                );
                if (empty($has3ColFk)) {
                    Schema::table('stock_movements', function (Blueprint $table) {
                        $table->foreign(['tenant_id', 'store_id', 'order_id'], 'stock_movements_tenant_store_order_composite_fk')
                            ->references(['tenant_id', 'store_id', 'id'])->on('orders')
                            ->onDelete('restrict')->onUpdate('cascade');
                    });
                }
            }
        }

        // 3. DB-013: Unique return item + guard over-refund
        if (Schema::hasTable('order_return_items')) {
            $hasOriUnique = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "order_return_items" AND INDEX_NAME = "uq_ori_return_item"'
            );
            if (empty($hasOriUnique)) {
                Schema::table('order_return_items', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'order_return_id', 'order_item_id'], 'uq_ori_return_item');
                });
            }
        }

        if ($driver !== 'sqlite' && Schema::hasTable('payment_refunds')) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_refunds_prevent_overrefund');
            DB::unprepared("
                CREATE TRIGGER trg_payment_refunds_prevent_overrefund
                BEFORE INSERT ON payment_refunds
                FOR EACH ROW
                BEGIN
                    DECLARE orig_payment_amount BIGINT;
                    DECLARE total_already_refunded DECIMAL(15,2);

                    IF NEW.financial_status = 'settled' OR NEW.request_status = 'approved' THEN
                        SELECT amount INTO orig_payment_amount
                        FROM payments
                        WHERE id = NEW.payment_id AND tenant_id = NEW.tenant_id;

                        IF orig_payment_amount IS NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refunds: payment induk tidak ditemukan pada tenant ini.';
                        END IF;

                        SELECT COALESCE(SUM(amount), 0.00) INTO total_already_refunded
                        FROM payment_refunds
                        WHERE payment_id = NEW.payment_id
                          AND tenant_id = NEW.tenant_id
                          AND financial_status = 'settled';

                        IF (total_already_refunded + NEW.amount) > orig_payment_amount THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Total refund melebihi nominal pembayaran yang di-capture (over-refund guard).';
                        END IF;
                    END IF;
                END;
            ");
        }

        // 4. DB-015: Rescope idempotency key ke (tenant_id, idempotency_key)
        $idempTables = [
            'payments' => 'payments_idempotency_key_unique',
            'stock_movements' => 'stock_movements_idempotency_key_unique',
            'ingredient_stock_movements' => 'ingredient_stock_movements_idempotency_key_unique',
            'cash_drawer_movements' => 'cash_drawer_movements_idempotency_key_unique',
        ];

        foreach ($idempTables as $tbl => $idxName) {
            if (Schema::hasTable($tbl)) {
                $hasOld = DB::select(
                    'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$tbl, $idxName]
                );
                if (! empty($hasOld)) {
                    Schema::table($tbl, function (Blueprint $table) use ($idxName) {
                        $table->dropUnique($idxName);
                    });
                }

                Schema::table($tbl, function (Blueprint $table) use ($idxName) {
                    $table->unique(['tenant_id', 'idempotency_key'], $idxName);
                });
            }
        }

        // 5. DB-008: Konversi single-column FKs ke composite FK
        $compositeFkReplacements = [
            // [table, oldFk, [childCols], parentTable, [parentCols], onDelete]
            ['discount_redemptions', 'fk_dr_customer', ['tenant_id', 'customer_id'], 'customers', ['tenant_id', 'id'], 'restrict'],
            ['discount_redemptions', 'fk_dr_user', ['tenant_id', 'redeemed_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['parked_carts', 'fk_pc_customer', ['tenant_id', 'customer_id'], 'customers', ['tenant_id', 'id'], 'restrict'],
            ['parked_carts', 'fk_pc_resumed_user', ['tenant_id', 'resumed_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['parked_carts', 'fk_pc_converted_order', ['tenant_id', 'converted_order_id'], 'orders', ['tenant_id', 'id'], 'restrict'],
            ['payment_attempts', 'payment_attempts_initiated_by_user_id_foreign', ['tenant_id', 'initiated_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['product_cost_histories', 'pch_changed_by_foreign', ['tenant_id', 'changed_by'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_approval_logs', 'palog_actor_fk', ['tenant_id', 'actor_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_approval_requests', 'pareq_submitted_user_fk', ['tenant_id', 'submitted_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_approval_requests', 'pareq_approved_user_fk', ['tenant_id', 'approved_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_orders', 'po_creator_fk', ['tenant_id', 'created_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_orders', 'po_approver_fk', ['tenant_id', 'approved_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_orders', 'po_releaser_fk', ['tenant_id', 'released_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_order_revisions', 'po_rev_user_fk', ['tenant_id', 'amended_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_requests', 'pr_requested_by_user_fk', ['tenant_id', 'requested_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['purchase_requests', 'pr_approved_by_user_fk', ['tenant_id', 'approved_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['receipt_print_logs', 'fk_rpl_device', ['tenant_id', 'store_id', 'device_id'], 'devices', ['tenant_id', 'store_id', 'id'], 'restrict'],
            ['receipt_print_logs', 'fk_rpl_shift', ['tenant_id', 'store_id', 'shift_id'], 'shifts', ['tenant_id', 'store_id', 'id'], 'restrict'],
            ['supplier_invoices', 'sinv_approved_by_fk', ['tenant_id', 'approved_payable_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['supplier_invoices', 'sinv_hold_approved_by_fk', ['tenant_id', 'hold_approved_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['supplier_invoice_grn_matches', 'sigm_var_approver_fk', ['tenant_id', 'variance_approved_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['goods_received_notes', 'grn_posted_by_fk', ['tenant_id', 'posted_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['goods_received_notes', 'grn_received_by_fk', ['tenant_id', 'received_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
            ['goods_received_notes', 'grn_reversed_by_fk', ['tenant_id', 'reversed_by_user_id'], 'users', ['tenant_id', 'id'], 'restrict'],
        ];

        foreach ($compositeFkReplacements as $item) {
            [$table, $fkName, $childCols, $parentTable, $parentCols, $onDelete] = $item;

            if (Schema::hasTable($table) && Schema::hasTable($parentTable)) {
                $hasExisting = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    [$table, $fkName]
                );
                if (! empty($hasExisting)) {
                    Schema::table($table, function (Blueprint $t) use ($fkName) {
                        $t->dropForeign($fkName);
                    });
                }

                $hasExistingIndex = DB::select(
                    'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $fkName]
                );
                if (! empty($hasExistingIndex)) {
                    Schema::table($table, function (Blueprint $t) use ($fkName) {
                        $t->dropIndex($fkName);
                    });
                }

                Schema::table($table, function (Blueprint $t) use ($fkName, $childCols, $parentTable, $parentCols, $onDelete) {
                    $fk = $t->foreign($childCols, $fkName)
                        ->references($parentCols)->on($parentTable)
                        ->onUpdate('cascade');

                    $fk->onDelete($onDelete);
                });
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Rollback FK komposit discounts
        if (Schema::hasTable('discounts')) {
            $hasFk = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "discounts" AND CONSTRAINT_NAME = "fk_discounts_tenant_store"'
            );
            if (! empty($hasFk)) {
                Schema::table('discounts', function (Blueprint $table) {
                    $table->dropForeign('fk_discounts_tenant_store');
                });
            }
        }

        // Rollback FK 3-kolom orders
        if (Schema::hasTable('orders')) {
            if (Schema::hasTable('order_items')) {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->dropForeign('order_items_tenant_store_order_composite_fk');
                    $table->foreign(['tenant_id', 'order_id'], 'order_items_order_id_composite_foreign')
                        ->references(['tenant_id', 'id'])->on('orders')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }

            if (Schema::hasTable('payments')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropForeign('payments_tenant_store_order_composite_fk');
                    $table->foreign(['tenant_id', 'order_id'], 'payments_order_id_composite_foreign')
                        ->references(['tenant_id', 'id'])->on('orders')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }

            if (Schema::hasTable('stock_movements')) {
                Schema::table('stock_movements', function (Blueprint $table) {
                    $table->dropForeign('stock_movements_tenant_store_order_composite_fk');
                    $table->foreign(['tenant_id', 'order_id'], 'stock_movements_order_id_composite_foreign')
                        ->references(['tenant_id', 'id'])->on('orders')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
            }

            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_tenant_store_id_unique');
            });
        }

        // Rollback trigger overrefund
        if ($driver !== 'sqlite' && Schema::hasTable('payment_refunds')) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_refunds_prevent_overrefund');
        }

        // Rollback unique order_return_items
        if (Schema::hasTable('order_return_items')) {
            Schema::table('order_return_items', function (Blueprint $table) {
                $table->dropUnique('uq_ori_return_item');
            });
        }

        // Rollback idempotency unique indexes to single-column
        $idempTables = [
            'payments' => 'payments_idempotency_key_unique',
            'stock_movements' => 'stock_movements_idempotency_key_unique',
            'ingredient_stock_movements' => 'ingredient_stock_movements_idempotency_key_unique',
            'cash_drawer_movements' => 'cash_drawer_movements_idempotency_key_unique',
        ];

        foreach ($idempTables as $tbl => $idxName) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($idxName) {
                    $table->dropUnique($idxName);
                    $table->unique('idempotency_key', $idxName);
                });
            }
        }
    }
};
