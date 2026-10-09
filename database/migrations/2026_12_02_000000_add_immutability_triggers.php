<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. payments
        DB::unprepared("
            CREATE TRIGGER trg_payments_before_update BEFORE UPDATE ON payments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments bersifat append-only: UPDATE dilarang.';
            END;
        ");
        DB::unprepared("
            CREATE TRIGGER trg_payments_before_delete BEFORE DELETE ON payments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments bersifat append-only: DELETE dilarang.';
            END;
        ");

        // 2. stock_movements
        DB::unprepared("
            CREATE TRIGGER trg_stock_movements_before_update BEFORE UPDATE ON stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements bersifat append-only: UPDATE dilarang.';
            END;
        ");
        DB::unprepared("
            CREATE TRIGGER trg_stock_movements_before_delete BEFORE DELETE ON stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements bersifat append-only: DELETE dilarang.';
            END;
        ");

        // 3. cash_drawer_movements
        DB::unprepared("
            CREATE TRIGGER trg_cash_drawer_movements_before_update BEFORE UPDATE ON cash_drawer_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_drawer_movements bersifat append-only: UPDATE dilarang.';
            END;
        ");
        DB::unprepared("
            CREATE TRIGGER trg_cash_drawer_movements_before_delete BEFORE DELETE ON cash_drawer_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_drawer_movements bersifat append-only: DELETE dilarang.';
            END;
        ");

        // 4. activity_logs
        DB::unprepared("
            CREATE TRIGGER trg_activity_logs_before_update BEFORE UPDATE ON activity_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_logs bersifat append-only: UPDATE dilarang.';
            END;
        ");
        DB::unprepared("
            CREATE TRIGGER trg_activity_logs_before_delete BEFORE DELETE ON activity_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_logs bersifat append-only: DELETE dilarang.';
            END;
        ");

        // ingredient_stock_movements and loyalty_ledger already have UPDATE triggers. Add DELETE.
        DB::unprepared("
            CREATE TRIGGER trg_ingredient_stock_movements_before_delete BEFORE DELETE ON ingredient_stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: DELETE dilarang.';
            END;
        ");
        DB::unprepared("
            CREATE TRIGGER trg_loyalty_ledger_before_delete BEFORE DELETE ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: DELETE dilarang.';
            END;
        ");

        // 5. orders
        DB::unprepared("
            CREATE TRIGGER trg_orders_before_delete BEFORE DELETE ON orders
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders tidak dapat dihapus (DELETE dilarang).';
            END;
        ");

        // Trigger orders sebelum file triggers.sql dihapus pasca Phase 12.
        DB::unprepared("
            CREATE TRIGGER trg_orders_before_update BEFORE UPDATE ON orders
            FOR EACH ROW
            BEGIN
                IF OLD.order_status <=> 'pending' THEN
                    IF NEW.id <=> OLD.id = 0
                        OR NEW.user_id <=> OLD.user_id = 0
                        OR NEW.shift_id <=> OLD.shift_id = 0
                        OR NEW.discount_id <=> OLD.discount_id = 0
                        OR NEW.customer_id <=> OLD.customer_id = 0
                        OR NEW.table_id <=> OLD.table_id = 0
                        OR NEW.order_type <=> OLD.order_type = 0
                        OR NEW.order_code <=> OLD.order_code = 0
                        OR NEW.idempotency_key <=> OLD.idempotency_key = 0
                        OR NEW.order_date <=> OLD.order_date = 0
                        OR NEW.subtotal_amount <=> OLD.subtotal_amount = 0
                        OR NEW.discount_amount <=> OLD.discount_amount = 0
                        OR NEW.tax_amount <=> OLD.tax_amount = 0
                        OR NEW.service_charge_amount <=> OLD.service_charge_amount = 0
                        OR NEW.order_amount <=> OLD.order_amount = 0
                        OR NEW.voided_by <=> OLD.voided_by = 0
                        OR NEW.void_reason <=> OLD.void_reason = 0
                        OR NEW.voided_at <=> OLD.voided_at = 0
                        OR NEW.created_at <=> OLD.created_at = 0
                        OR NEW.tenant_id <=> OLD.tenant_id = 0
                        OR NEW.store_id <=> OLD.store_id = 0
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: field kepemilikan/identitas/finansial tidak dapat diubah saat pending (UPDATE dilarang).';
                    END IF;
                ELSE
                    IF NEW.order_status <=> 'void' AND OLD.order_status <=> 'paid' THEN
                        IF NEW.id <=> OLD.id = 0
                            OR NEW.user_id <=> OLD.user_id = 0
                            OR NEW.shift_id <=> OLD.shift_id = 0
                            OR NEW.discount_id <=> OLD.discount_id = 0
                            OR NEW.customer_id <=> OLD.customer_id = 0
                            OR NEW.table_id <=> OLD.table_id = 0
                            OR NEW.order_type <=> OLD.order_type = 0
                            OR NEW.order_code <=> OLD.order_code = 0
                            OR NEW.idempotency_key <=> OLD.idempotency_key = 0
                            OR NEW.order_date <=> OLD.order_date = 0
                            OR NEW.subtotal_amount <=> OLD.subtotal_amount = 0
                            OR NEW.discount_amount <=> OLD.discount_amount = 0
                            OR NEW.tax_amount <=> OLD.tax_amount = 0
                            OR NEW.service_charge_amount <=> OLD.service_charge_amount = 0
                            OR NEW.order_amount <=> OLD.order_amount = 0
                            OR NEW.cash_received <=> OLD.cash_received = 0
                            OR NEW.order_change <=> OLD.order_change = 0
                            OR NEW.payment_method <=> OLD.payment_method = 0
                            OR NEW.snap_token <=> OLD.snap_token = 0
                            OR NEW.created_at <=> OLD.created_at = 0
                            OR NEW.tenant_id <=> OLD.tenant_id = 0
                            OR NEW.store_id <=> OLD.store_id = 0
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: saat transisi void, hanya void_reason/voided_by/voided_at yang boleh berubah (UPDATE dilarang).';
                        END IF;
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: pesanan final tidak dapat diedit kecuali void (UPDATE dilarang).';
                    END IF;
                END IF;
            END;
        ");

        // 6. order_items
        DB::unprepared("
            CREATE TRIGGER trg_order_items_before_delete BEFORE DELETE ON order_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_items tidak dapat dihapus (DELETE dilarang).';
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_order_items_before_update BEFORE UPDATE ON order_items
            FOR EACH ROW
            BEGIN
                IF NEW.id <=> OLD.id = 0
                    OR NEW.order_id <=> OLD.order_id = 0
                    OR NEW.product_id <=> OLD.product_id = 0
                    OR NEW.qty <=> OLD.qty = 0
                    OR NEW.order_price <=> OLD.order_price = 0
                    OR NEW.order_subtotal <=> OLD.order_subtotal = 0
                    OR NEW.options <=> OLD.options = 0
                    OR NEW.notes <=> OLD.notes = 0
                    OR NEW.created_at <=> OLD.created_at = 0
                    OR NEW.tenant_id <=> OLD.tenant_id = 0
                    OR NEW.store_id <=> OLD.store_id = 0
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_items: hanya preparation_status dan processed_by yang boleh berubah (UPDATE dilarang).';
                END IF;
            END;
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payments_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payments_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movements_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movements_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cash_drawer_movements_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cash_drawer_movements_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_activity_logs_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_activity_logs_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_before_update');
    }
};
