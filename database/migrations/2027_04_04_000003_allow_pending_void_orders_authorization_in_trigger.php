<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');
            DB::unprepared("
                CREATE TRIGGER trg_orders_before_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    -- 1. Kolom identitas & audit immutable tidak boleh diubah
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
                           OR (NEW.voided_at <=> OLD.voided_at = 0)
                           OR (NEW.print_count < OLD.print_count)
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: saat status paid, hanya print_count yang boleh bertambah (UPDATE dilarang).';
                        END IF;

                    -- 5. Otorisasi Void Tertunda (Penyelesaian Antrean Persetujuan / Rejection)
                    ELSEIF OLD.order_status <=> 'void' AND NEW.order_status <=> 'void' AND OLD.voided_by IS NULL THEN
                        IF (NEW.subtotal_amount <=> OLD.subtotal_amount = 0)
                           OR (NEW.discount_amount <=> OLD.discount_amount = 0)
                           OR (NEW.tax_amount <=> OLD.tax_amount = 0)
                           OR (NEW.service_charge_amount <=> OLD.service_charge_amount = 0)
                           OR (NEW.order_amount <=> OLD.order_amount = 0)
                           OR (NEW.cash_received <=> OLD.cash_received = 0)
                           OR (NEW.order_change <=> OLD.order_change = 0)
                           OR (NEW.payment_method <=> OLD.payment_method = 0)
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: saat otorisasi void tertunda, data finansial tidak dapat diubah (UPDATE dilarang).';
                        END IF;

                    -- 6. Status final lainnya (void terotorisasi, cancelled, expired, failed)
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: pesanan final tidak dapat diedit kecuali void (UPDATE dilarang).';
                    END IF;
                END;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_immutable_update');
        }
    }
};
