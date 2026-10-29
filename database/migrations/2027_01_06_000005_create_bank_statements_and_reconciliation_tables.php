<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Tabel bank_statements (Header Rekening Koran Impor)
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->string('statement_number', 64);
            $table->date('statement_date');
            $table->decimal('opening_balance', 18, 2)->default(0.00);
            $table->decimal('closing_balance', 18, 2)->default(0.00);
            $table->text('imported_file_path')->nullable();
            $table->unsignedBigInteger('imported_by_user_id')->nullable();
            $table->string('status', 32)->default('OPEN'); // OPEN, RECONCILED
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_bs_tenant_id');
            $table->unique(['tenant_id', 'statement_number'], 'uq_bs_number');

            $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_bs_account')
                ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('imported_by_user_id', 'fk_bs_imported_by')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT chk_bs_status
                CHECK (status IN ('OPEN', 'RECONCILED'))");
        }

        // 2. Tabel bank_statement_lines (Baris Rekening Koran)
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('bank_statement_id');
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->unsignedInteger('line_number');
            $table->date('value_date');
            $table->string('external_reference', 100)->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0.00);
            $table->decimal('credit_amount', 18, 2)->default(0.00);
            $table->decimal('running_balance', 18, 2)->default(0.00);
            $table->text('description')->nullable();
            $table->string('line_fingerprint_hash', 64); // SHA-256 anti-duplicate import
            $table->string('match_status', 32)->default('UNMATCHED'); // UNMATCHED, MATCHED, ADJUSTED, EXCLUDED
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_bsl_tenant_id');
            $table->unique(['tenant_id', 'cash_bank_account_id', 'line_fingerprint_hash'], 'uq_bsl_fingerprint');
            $table->unique(['bank_statement_id', 'line_number'], 'uq_bsl_statement_line');

            $table->index(['tenant_id', 'bank_statement_id', 'match_status', 'value_date'], 'idx_bsl_match_status');

            $table->foreign(['tenant_id', 'bank_statement_id'], 'fk_bsl_statement')
                ->references(['tenant_id', 'id'])->on('bank_statements')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_bsl_account')
                ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT chk_bsl_match_status
                CHECK (match_status IN ('UNMATCHED', 'MATCHED', 'ADJUSTED', 'EXCLUDED'))");

            DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT chk_bsl_amounts
                CHECK (debit_amount >= 0 AND credit_amount >= 0)');
        }

        // 3. Tabel bank_reconciliation_sessions (Sesi Rekonsiliasi Bank)
        Schema::create('bank_reconciliation_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->string('session_number', 64);
            $table->date('statement_end_date');
            $table->decimal('statement_ending_balance', 18, 2);
            $table->decimal('book_ending_balance', 18, 2);
            $table->decimal('unreconciled_difference', 18, 2)->default(0.00);
            $table->string('status', 32)->default('IN_PROGRESS'); // IN_PROGRESS, COMPLETED, REOPENED
            $table->unsignedBigInteger('started_by_user_id');
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_brs_tenant_id');
            $table->unique(['tenant_id', 'session_number'], 'uq_brs_number');

            $table->index(['tenant_id', 'cash_bank_account_id', 'statement_end_date', 'status'], 'idx_brs_account_period');

            $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_brs_account')
                ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('started_by_user_id', 'fk_brs_started_by')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('completed_by_user_id', 'fk_brs_completed_by')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE bank_reconciliation_sessions ADD CONSTRAINT chk_brs_status
                CHECK (status IN ('IN_PROGRESS', 'COMPLETED', 'REOPENED'))");
        }

        // 4. Tabel bank_reconciliation_matches (Pencocokan Transaksi vs Baris Koran)
        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('reconciliation_session_id');
            $table->unsignedBigInteger('bank_statement_line_id');
            $table->unsignedBigInteger('cash_bank_transaction_id');
            $table->string('match_type', 32); // EXACT_AUTO, MANUAL_1_TO_1, MANUAL_SPLIT, ADJUSTMENT_ENTRY
            $table->decimal('matched_amount', 18, 2);
            $table->unsignedBigInteger('matched_by_user_id');
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_brm_tenant_id');
            $table->unique(['tenant_id', 'bank_statement_line_id', 'cash_bank_transaction_id'], 'uq_brm_pair');

            $table->foreign(['tenant_id', 'reconciliation_session_id'], 'fk_brm_session')
                ->references(['tenant_id', 'id'])->on('bank_reconciliation_sessions')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'bank_statement_line_id'], 'fk_brm_line')
                ->references(['tenant_id', 'id'])->on('bank_statement_lines')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'cash_bank_transaction_id'], 'fk_brm_txn')
                ->references(['tenant_id', 'id'])->on('cash_bank_transactions')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('matched_by_user_id', 'fk_brm_matched_by')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE bank_reconciliation_matches ADD CONSTRAINT chk_brm_match_type
                CHECK (match_type IN ('EXACT_AUTO', 'MANUAL_1_TO_1', 'MANUAL_SPLIT', 'ADJUSTMENT_ENTRY'))");

            DB::statement('ALTER TABLE bank_reconciliation_matches ADD CONSTRAINT chk_brm_matched_amount
                CHECK (matched_amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_matches');
        Schema::dropIfExists('bank_reconciliation_sessions');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
