<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_payments_prevent_overpayment');

        DB::unprepared(<<<'SQL'
        CREATE TRIGGER trg_payments_prevent_overpayment
        BEFORE INSERT ON payments
        FOR EACH ROW
        BEGIN
            DECLARE total_captured BIGINT;
            DECLARE order_total BIGINT;

            -- Trigger overpayment guard mengabaikan penyelesaian selisih tukar guling retail (EXC-*)
            IF NEW.status = 'captured' AND (NEW.reference_number IS NULL OR NOT (NEW.reference_number LIKE 'EXC-%')) THEN
                SELECT COALESCE(SUM(amount), 0) INTO total_captured
                    FROM payments
                    WHERE order_id = NEW.order_id AND status = 'captured';

                SELECT order_amount INTO order_total
                    FROM orders WHERE id = NEW.order_id;

                IF (total_captured + NEW.amount) > order_total THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Total pembayaran captured melebihi order_amount -- ditolak oleh trigger overpayment guard.';
                END IF;
            END IF;
        END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_payments_prevent_overpayment');

        DB::unprepared(<<<'SQL'
        CREATE TRIGGER trg_payments_prevent_overpayment
        BEFORE INSERT ON payments
        FOR EACH ROW
        BEGIN
            DECLARE total_captured BIGINT;
            DECLARE order_total BIGINT;

            IF NEW.status = 'captured' THEN
                SELECT COALESCE(SUM(amount), 0) INTO total_captured
                    FROM payments
                    WHERE order_id = NEW.order_id AND status = 'captured';

                SELECT order_amount INTO order_total
                    FROM orders WHERE id = NEW.order_id;

                IF (total_captured + NEW.amount) > order_total THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Total pembayaran captured melebihi order_amount -- ditolak oleh trigger overpayment guard.';
                END IF;
            END IF;
        END
        SQL);
    }
};
