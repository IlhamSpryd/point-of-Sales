<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_sync_account;');

            DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loyalty_ledger_sync_account
            AFTER INSERT ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                INSERT INTO customer_loyalty_accounts (customer_id, tenant_id, current_points, lifetime_points_earned, created_at, updated_at)
                VALUES (
                    NEW.customer_id,
                    NEW.tenant_id,
                    NEW.balance_after,
                    GREATEST(NEW.points, 0),
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    current_points = NEW.balance_after,
                    lifetime_points_earned = lifetime_points_earned + GREATEST(NEW.points, 0),
                    updated_at = NOW();
            END
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_sync_account;');

            DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loyalty_ledger_sync_account
            AFTER INSERT ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                INSERT INTO customer_loyalty_accounts (customer_id, current_points, lifetime_points_earned, created_at, updated_at)
                VALUES (
                    NEW.customer_id,
                    NEW.balance_after,
                    GREATEST(NEW.points, 0),
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    current_points = NEW.balance_after,
                    lifetime_points_earned = lifetime_points_earned + GREATEST(NEW.points, 0),
                    updated_at = NOW();
            END
            SQL);
        }
    }
};
