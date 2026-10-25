<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T2.2: Expand Schema pada tabel orders:
     * - Menambahkan kolom table_number sebagai immutable snapshot
     * - Mengganti FK 2-kolom lama dengan Composite Foreign Key 3-kolom:
     *   (tenant_id, store_id, table_id) -> tables(tenant_id, store_id, id) ON DELETE RESTRICT
     * - Memperbarui trigger imutabilitas trg_orders_before_update
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'table_number')) {
                $table->string('table_number', 50)->nullable()->after('table_id');
            }
        });

        // 1. Drop trigger sementara sebelum backfill agar tidak memblokir penambahan kolom snapshot pada pesanan historis
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');

            DB::statement('
                UPDATE orders o
                JOIN tables t ON o.table_id = t.id AND o.tenant_id = t.tenant_id AND o.store_id = t.store_id
                SET o.table_number = t.table_name
                WHERE o.table_id IS NOT NULL AND o.table_number IS NULL
            ');
        }

        // 2. Pasang index & Composite FK (tenant_id, store_id, table_id)
        $fkName = 'orders_tenant_store_table_composite_foreign';
        $existingFk = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "orders" AND CONSTRAINT_NAME = ?',
            [$fkName]
        );

        if (empty($existingFk)) {
            Schema::table('orders', function (Blueprint $table) use ($fkName) {
                $table->foreign(['tenant_id', 'store_id', 'table_id'], $fkName)
                    ->references(['tenant_id', 'store_id', 'id'])
                    ->on('tables')
                    ->onDelete('RESTRICT')
                    ->onUpdate('CASCADE');
            });
        }

        // Drop FK lama (orders_table_id_composite_foreign) jika FK komposit 3-kolom sudah aktif
        $oldFk = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "orders" AND CONSTRAINT_NAME = "orders_table_id_composite_foreign"'
        );
        if (! empty($oldFk)) {
            Schema::table('orders', function (Blueprint $table) {
                try {
                    $table->dropForeign('orders_table_id_composite_foreign');
                } catch (Throwable) {
                }
            });
        }

        // 3. Perbarui trigger imutabilitas trg_orders_before_update untuk melindungi table_number
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');

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
                            OR (OLD.table_number IS NOT NULL AND NEW.table_number <=> OLD.table_number = 0)
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
                    ELSEIF NEW.order_status <=> 'void' AND OLD.order_status <=> 'paid' THEN
                        IF NEW.id <=> OLD.id = 0
                            OR NEW.user_id <=> OLD.user_id = 0
                            OR NEW.shift_id <=> OLD.shift_id = 0
                            OR NEW.discount_id <=> OLD.discount_id = 0
                            OR NEW.customer_id <=> OLD.customer_id = 0
                            OR NEW.table_id <=> OLD.table_id = 0
                            OR (OLD.table_number IS NOT NULL AND NEW.table_number <=> OLD.table_number = 0)
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
                    ELSEIF NEW.order_status <=> 'paid' AND OLD.order_status <=> 'paid' THEN
                        IF NEW.id <=> OLD.id = 0
                            OR NEW.user_id <=> OLD.user_id = 0
                            OR NEW.shift_id <=> OLD.shift_id = 0
                            OR NEW.discount_id <=> OLD.discount_id = 0
                            OR NEW.customer_id <=> OLD.customer_id = 0
                            OR NEW.table_id <=> OLD.table_id = 0
                            OR (OLD.table_number IS NOT NULL AND NEW.table_number <=> OLD.table_number = 0)
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
                            OR NEW.voided_by <=> OLD.voided_by = 0
                            OR NEW.void_reason <=> OLD.void_reason = 0
                            OR NEW.voided_at <=> OLD.voided_at = 0
                            OR NEW.created_at <=> OLD.created_at = 0
                            OR NEW.tenant_id <=> OLD.tenant_id = 0
                            OR NEW.store_id <=> OLD.store_id = 0
                            OR NEW.print_count < OLD.print_count
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: saat status paid, hanya print_count yang boleh bertambah (UPDATE dilarang).';
                        END IF;
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: pesanan final tidak dapat diedit kecuali void (UPDATE dilarang).';
                    END IF;
                END;
            ");
        }
    }

    public function down(): void
    {
        // Restore FK lama
        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropForeign('orders_tenant_store_table_composite_foreign');
            } catch (Throwable) {
            }
            try {
                $table->foreign(['tenant_id', 'table_id'], 'orders_table_id_composite_foreign')
                    ->references(['tenant_id', 'id'])
                    ->on('tables')
                    ->onUpdate('CASCADE');
            } catch (Throwable) {
            }
            if (Schema::hasColumn('orders', 'table_number')) {
                $table->dropColumn('table_number');
            }
        });
    }
};
