<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // ingredient_stock_movements trigger update
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_update');
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER trg_ingredient_stock_movements_no_update
        BEFORE UPDATE ON ingredient_stock_movements
        FOR EACH ROW
        BEGIN
            IF NOT (
                OLD.id <=> NEW.id AND
                OLD.ingredient_id <=> NEW.ingredient_id AND
                OLD.order_id <=> NEW.order_id AND
                OLD.order_item_id <=> NEW.order_item_id AND
                OLD.type <=> NEW.type AND
                OLD.quantity <=> NEW.quantity AND
                OLD.unit_cost <=> NEW.unit_cost AND
                OLD.reason <=> NEW.reason AND
                OLD.idempotency_key <=> NEW.idempotency_key AND
                OLD.created_by <=> NEW.created_by AND
                OLD.created_at <=> NEW.created_at AND
                OLD.tenant_id IS NULL
            ) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: UPDATE dilarang.';
            END IF;
        END
        SQL);

        // loyalty_ledger trigger update
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER trg_loyalty_ledger_no_update
        BEFORE UPDATE ON loyalty_ledger
        FOR EACH ROW
        BEGIN
            IF NOT (
                OLD.id <=> NEW.id AND
                OLD.customer_id <=> NEW.customer_id AND
                OLD.order_id <=> NEW.order_id AND
                OLD.type <=> NEW.type AND
                OLD.points <=> NEW.points AND
                OLD.balance_after <=> NEW.balance_after AND
                OLD.reference <=> NEW.reference AND
                OLD.prev_hash <=> NEW.prev_hash AND
                OLD.entry_hash <=> NEW.entry_hash AND
                OLD.created_by <=> NEW.created_by AND
                OLD.created_at <=> NEW.created_at AND
                OLD.tenant_id IS NULL
            ) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: UPDATE dilarang.';
            END IF;
        END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_update');
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER trg_ingredient_stock_movements_no_update
        BEFORE UPDATE ON ingredient_stock_movements
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: UPDATE dilarang.';
        END
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER trg_loyalty_ledger_no_update
        BEFORE UPDATE ON loyalty_ledger
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: UPDATE dilarang.';
        END
        SQL);
    }
};
