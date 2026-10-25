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
     * Remediasi P0 (Critical/Blocker):
     * 1. DB-001: Rescope shifts_one_open_per_user_unique ke (tenant_id, store_id, open_shift_lock_key).
     * 2. DB-002: Perbaiki trg_loyalty_ledger_hash_chain agar unik deterministik (microsecond & nonce).
     * 3. DB-003: Drop trigger duplikat BEFORE DELETE pada ingredient_stock_movements dan loyalty_ledger.
     * 4. DB-004: Perbaiki trg_orders_before_update agar transisi pending->paid legal dengan proteksi finansial.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. DB-001: Rescope shifts_one_open_per_user_unique
        if (Schema::hasTable('shifts')) {
            $hasUserUnique = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                ['shifts', 'shifts_one_open_per_user_unique']
            );

            if (! empty($hasUserUnique)) {
                Schema::table('shifts', function (Blueprint $table) {
                    $table->dropUnique('shifts_one_open_per_user_unique');
                });
            }

            Schema::table('shifts', function (Blueprint $table) {
                $table->unique(['tenant_id', 'store_id', 'open_shift_lock_key'], 'shifts_one_open_per_user_unique');
            });
        }

        // 2. DB-002: Perbaiki trg_loyalty_ledger_hash_chain
        if ($driver !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_hash_chain');
            DB::unprepared("
                CREATE TRIGGER trg_loyalty_ledger_hash_chain
                BEFORE INSERT ON loyalty_ledger
                FOR EACH ROW
                BEGIN
                    DECLARE last_hash CHAR(64);
                    SELECT entry_hash INTO last_hash
                    FROM loyalty_ledger
                    WHERE customer_id = NEW.customer_id
                    ORDER BY id DESC
                    LIMIT 1;

                    SET NEW.prev_hash = last_hash;
                    SET NEW.entry_hash = SHA2(CONCAT_WS('|',
                        NEW.customer_id,
                        IFNULL(NEW.order_id, ''),
                        NEW.type,
                        NEW.points,
                        NEW.balance_after,
                        IFNULL(NEW.reference, ''),
                        IFNULL(NEW.created_by, ''),
                        IFNULL(last_hash, ''),
                        CURRENT_TIMESTAMP(6),
                        UUID()
                    ), 256);
                END;
            ");
        }

        // 3. DB-003: Drop trigger duplikat BEFORE DELETE
        if ($driver !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_delete');

            // Pastikan trigger standard before_delete tetap ada dan valid
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_before_delete');
            DB::unprepared("
                CREATE TRIGGER trg_ingredient_stock_movements_before_delete
                BEFORE DELETE ON ingredient_stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: DELETE dilarang.';
                END;
            ");

            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_before_delete');
            DB::unprepared("
                CREATE TRIGGER trg_loyalty_ledger_before_delete
                BEFORE DELETE ON loyalty_ledger
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: DELETE dilarang.';
                END;
            ");
        }

        // 4. DB-004: Perbaiki trg_orders_before_update
        if ($driver !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');
            DB::unprepared("
                CREATE TRIGGER trg_orders_before_update BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    -- 1. Invarian Tenant, Store, & Identitas Keras (Tidak boleh pernah berubah)
                    IF (NEW.id <=> OLD.id = 0)
                       OR (NEW.tenant_id <=> OLD.tenant_id = 0)
                       OR (NEW.store_id <=> OLD.store_id = 0)
                       OR (NEW.order_code <=> OLD.order_code = 0)
                       OR (NEW.idempotency_key <=> OLD.idempotency_key = 0)
                       OR (NEW.created_at <=> OLD.created_at = 0)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: tenant_id, store_id, order_code, idempotency_key tidak dapat diubah (UPDATE dilarang).';
                    END IF;

                    -- 2. Transisi dari status PENDING
                    IF OLD.order_status <=> 'pending' THEN
                        IF NEW.order_status <=> 'paid' THEN
                            -- Transisi pending -> paid:
                            -- Finansial pokok tidak boleh dimanipulasi saat pelunasan
                            IF (NEW.subtotal_amount <=> OLD.subtotal_amount = 0)
                               OR (NEW.discount_amount <=> OLD.discount_amount = 0)
                               OR (NEW.tax_amount <=> OLD.tax_amount = 0)
                               OR (NEW.service_charge_amount <=> OLD.service_charge_amount = 0)
                               OR (NEW.order_amount <=> OLD.order_amount = 0)
                            THEN
                                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: nominal finansial pesanan tidak dapat diubah saat pelunasan (UPDATE dilarang).';
                            END IF;
                        ELSEIF NEW.order_status IN ('cancelled', 'expired', 'failed') THEN
                            IF (NEW.order_amount <=> OLD.order_amount = 0) THEN
                                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: nominal tidak dapat diubah saat pembatalan pesanan (UPDATE dilarang).';
                            END IF;
                        ELSEIF NEW.order_status <=> 'pending' THEN
                            -- Pembaruan saat masih pending
                            IF (NEW.user_id <=> OLD.user_id = 0)
                               OR (NEW.order_date <=> OLD.order_date = 0)
                               OR (NEW.subtotal_amount <=> OLD.subtotal_amount = 0)
                               OR (NEW.discount_amount <=> OLD.discount_amount = 0)
                               OR (NEW.tax_amount <=> OLD.tax_amount = 0)
                               OR (NEW.service_charge_amount <=> OLD.service_charge_amount = 0)
                               OR (NEW.order_amount <=> OLD.order_amount = 0)
                            THEN
                                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: field finansial tidak dapat diubah saat pending tanpa rekalkulasi (UPDATE dilarang).';
                            END IF;
                        ELSE
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: transisi status dari pending tidak valid (UPDATE dilarang).';
                        END IF;

                    -- 3. Transisi dari status PAID ke VOID
                    ELSEIF OLD.order_status <=> 'paid' AND NEW.order_status <=> 'void' THEN
                        IF (NEW.subtotal_amount <=> OLD.subtotal_amount = 0)
                           OR (NEW.discount_amount <=> OLD.discount_amount = 0)
                           OR (NEW.tax_amount <=> OLD.tax_amount = 0)
                           OR (NEW.service_charge_amount <=> OLD.service_charge_amount = 0)
                           OR (NEW.order_amount <=> OLD.order_amount = 0)
                           OR (NEW.cash_received <=> OLD.cash_received = 0)
                           OR (NEW.order_change <=> OLD.order_change = 0)
                           OR (NEW.payment_method <=> OLD.payment_method = 0)
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: saat transisi void, data pembayaran & finansial tidak dapat diubah (UPDATE dilarang).';
                        END IF;

                    -- 4. Pembaruan saat status PAID (hanya print_count yang boleh bertambah)
                    ELSEIF OLD.order_status <=> 'paid' AND NEW.order_status <=> 'paid' THEN
                        IF (NEW.user_id <=> OLD.user_id = 0)
                           OR (NEW.shift_id <=> OLD.shift_id = 0)
                           OR (NEW.discount_id <=> OLD.discount_id = 0)
                           OR (NEW.customer_id <=> OLD.customer_id = 0)
                           OR (NEW.table_id <=> OLD.table_id = 0)
                           OR (NEW.order_type <=> OLD.order_type = 0)
                           OR (NEW.order_date <=> OLD.order_date = 0)
                           OR (NEW.subtotal_amount <=> OLD.subtotal_amount = 0)
                           OR (NEW.discount_amount <=> OLD.discount_amount = 0)
                           OR (NEW.tax_amount <=> OLD.tax_amount = 0)
                           OR (NEW.service_charge_amount <=> OLD.service_charge_amount = 0)
                           OR (NEW.order_amount <=> OLD.order_amount = 0)
                           OR (NEW.cash_received <=> OLD.cash_received = 0)
                           OR (NEW.order_change <=> OLD.order_change = 0)
                           OR (NEW.payment_method <=> OLD.payment_method = 0)
                           OR (NEW.snap_token <=> OLD.snap_token = 0)
                           OR (NEW.voided_by <=> OLD.voided_by = 0)
                           OR (NEW.void_reason <=> OLD.void_reason = 0)
                           OR (NEW.voided_at <=> OLD.voided_at = 0)
                           OR (NEW.print_count < OLD.print_count)
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: saat status paid, hanya print_count yang boleh bertambah (UPDATE dilarang).';
                        END IF;

                    -- 5. Status final lainnya (void, cancelled, expired, failed)
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: pesanan final tidak dapat diedit kecuali void (UPDATE dilarang).';
                    END IF;
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Rollback DB-001
        if (Schema::hasTable('shifts')) {
            $hasRescoped = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                ['shifts', 'shifts_one_open_per_user_unique']
            );

            if (! empty($hasRescoped)) {
                Schema::table('shifts', function (Blueprint $table) {
                    $table->dropUnique('shifts_one_open_per_user_unique');
                });
            }

            Schema::table('shifts', function (Blueprint $table) {
                $table->unique('open_shift_lock_key', 'shifts_one_open_per_user_unique');
            });
        }

        // Rollback triggers to baseline
        if ($driver !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_hash_chain');
            DB::unprepared("
                CREATE TRIGGER trg_loyalty_ledger_hash_chain
                BEFORE INSERT ON loyalty_ledger
                FOR EACH ROW
                BEGIN
                    DECLARE last_hash CHAR(64);
                    SELECT entry_hash INTO last_hash
                    FROM loyalty_ledger
                    WHERE customer_id = NEW.customer_id
                    ORDER BY id DESC
                    LIMIT 1;

                    SET NEW.prev_hash = last_hash;
                    SET NEW.entry_hash = SHA2(CONCAT_WS('|',
                        NEW.customer_id, IFNULL(NEW.order_id, ''), NEW.type, NEW.points,
                        NEW.balance_after, IFNULL(last_hash, ''), NOW()
                    ), 256);
                END;
            ");

            // Restore duplicate trigger
            DB::unprepared("
                CREATE TRIGGER trg_ingredient_stock_movements_no_delete
                BEFORE DELETE ON ingredient_stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: DELETE dilarang.';
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_loyalty_ledger_no_delete
                BEFORE DELETE ON loyalty_ledger
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: DELETE dilarang.';
                END;
            ");
        }
    }
};
