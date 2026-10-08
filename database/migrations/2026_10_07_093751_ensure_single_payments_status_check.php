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
        // 1. Drop chk_payments_status_enum if present
        try {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_status_enum');
        } catch (Exception $e) {
            try {
                DB::statement('ALTER TABLE payments DROP CHECK chk_payments_status_enum');
            } catch (Exception $e2) {
                // Ignore if it doesn't exist
            }
        }

        // 2. Drop chk_payments_status if present, to recreate cleanly
        try {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_status');
        } catch (Exception $e) {
            try {
                DB::statement('ALTER TABLE payments DROP CHECK chk_payments_status');
            } catch (Exception $e2) {
                // Ignore
            }
        }

        // 3. Add the exact one needed
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (`status` in ('pending','captured','failed','refunded','voided'))");

        // 4. Verify via information_schema
        $count = DB::table('information_schema.table_constraints')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'payments')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->where('CONSTRAINT_NAME', 'like', '%status%')
            ->count();

        if ($count !== 1) {
            throw new Exception("Verification failed: expected exactly 1 CHECK constraint on payments status, found {$count}.");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasVoided = DB::table('payments')->where('status', 'voided')->exists();
        if ($hasVoided) {
            throw new Exception('Cannot rollback: there are payments with status voided.');
        }

        try {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_status');
        } catch (Exception $e) {
            try {
                DB::statement('ALTER TABLE payments DROP CHECK chk_payments_status');
            } catch (Exception $e2) {
                // Ignore
            }
        }

        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status_enum CHECK (`status` in ('pending','captured','failed','refunded'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (`status` in ('pending','captured','failed','refunded','voided'))");
    }
};
