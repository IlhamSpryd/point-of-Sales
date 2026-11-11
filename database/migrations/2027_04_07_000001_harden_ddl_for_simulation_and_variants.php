<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Indeks unik komposit & indeks analitik
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_order_code_unique');
            $table->unique(['tenant_id', 'order_code'], 'orders_order_code_unique');
            $table->index(['tenant_id', 'store_id', 'order_date', 'order_status'], 'idx_orders_tenant_store_date_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_employee_id_unique');
            $table->unique(['tenant_id', 'employee_id'], 'users_employee_id_unique');
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->index(['tenant_id', 'store_id', 'status', 'completed_at'], 'idx_order_returns_tenant_store_status_completed');
        });

        // 2. Perbesar secret_key pada webhook_subscriptions menjadi VARCHAR(512)
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE webhook_subscriptions MODIFY secret_key VARCHAR(512) NOT NULL');
        } else {
            Schema::table('webhook_subscriptions', function (Blueprint $table) {
                $table->string('secret_key', 512)->change();
            });
        }

        // 3. Perbaiki Triggers pada MariaDB / MySQL
        if ($driver === 'mysql') {
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

                    -- 2. Transisi / mutasi dari status PENDING
                    -- Izinkan pembaruan finansial (subtotal_amount, discount_amount, tax_amount, service_charge_amount, order_amount, dpp_amount, rounding_adjustment)
                    -- selama OLD.order_status <=> 'pending' (termasuk saat transisi pending -> paid / open tab checkout)
                    IF OLD.order_status <=> 'pending' THEN
                        IF NEW.order_status NOT IN ('pending', 'paid', 'cancelled', 'expired', 'failed', 'void') THEN
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

            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_before_update');
            DB::unprepared("
                CREATE TRIGGER trg_order_items_before_update
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                BEGIN
                    DECLARE v_order_status VARCHAR(50);

                    -- 1. Identitas permanen tidak boleh diubah
                    IF (NEW.id <=> OLD.id = 0)
                        OR (NEW.order_id <=> OLD.order_id = 0)
                        OR (NEW.tenant_id <=> OLD.tenant_id = 0)
                        OR (NEW.store_id <=> OLD.store_id = 0)
                        OR (NEW.created_at <=> OLD.created_at = 0)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_items: id, order_id, tenant_id, store_id, created_at tidak dapat diubah (UPDATE dilarang).';
                    END IF;

                    -- 2. Cek status order induk
                    SELECT order_status INTO v_order_status
                    FROM orders
                    WHERE id = OLD.order_id;

                    IF v_order_status <=> 'pending' THEN
                        -- Pesanan masih pending: izinkan perubahan kuantitas, harga, diskon baris, opsi, notes, dll.
                        BEGIN END;
                    ELSE
                        -- Pesanan non-pending (paid, void, cancelled, expired, failed):
                        -- Hanya status pemrosesan/persiapan dan processed_by yang boleh berubah
                        IF (NEW.product_id <=> OLD.product_id = 0)
                            OR (NEW.qty <=> OLD.qty = 0)
                            OR (NEW.order_price <=> OLD.order_price = 0)
                            OR (NEW.order_subtotal <=> OLD.order_subtotal = 0)
                            OR (NEW.options <=> OLD.options = 0)
                            OR (NEW.notes <=> OLD.notes = 0)
                            OR (NEW.item_discount_amount <=> OLD.item_discount_amount = 0)
                            OR (NEW.net_line_total <=> OLD.net_line_total = 0)
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_items: pesanan non-pending bersifat imutabel, hanya preparation_status dan processed_by yang boleh berubah (UPDATE dilarang).';
                        END IF;
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
            DB::statement('ALTER TABLE webhook_subscriptions MODIFY secret_key VARCHAR(128) NOT NULL');
        }

        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropIndex('idx_order_returns_tenant_store_status_completed');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_employee_id_unique');
            $table->unique('employee_id', 'users_employee_id_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_tenant_store_date_status');
            $table->dropUnique('orders_order_code_unique');
            $table->unique('order_code', 'orders_order_code_unique');
        });
    }
};
