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

        // 1. Tabel cash_bank_accounts (Master Akun Kas & Bank)
        Schema::create('cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('account_code', 64);
            $table->string('account_name', 128);
            $table->string('account_type', 32); // PETTY_CASH, POS_DRAWER_CLEARING, STORE_SAFE, BANK_ACCOUNT, PAYMENT_GATEWAY_CLEARING
            $table->unsignedBigInteger('gl_account_id');
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('bank_account_holder', 128)->nullable();
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('opening_balance', 18, 2)->default(0.00);
            $table->decimal('current_balance', 18, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_cba_tenant_id');
            $table->unique(['tenant_id', 'account_code'], 'uq_cba_code');
            $table->unique(['tenant_id', 'gl_account_id'], 'uq_cba_gl_account');

            $table->index(['tenant_id', 'store_id', 'account_type', 'is_active'], 'idx_cba_store_type');

            // Foreign Keys
            $table->foreign(['tenant_id', 'gl_account_id'], 'fk_cba_gl_account')
                ->references(['tenant_id', 'id'])->on('chart_of_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'fk_cba_store')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE cash_bank_accounts ADD CONSTRAINT chk_cba_account_type
                CHECK (account_type IN ('PETTY_CASH', 'POS_DRAWER_CLEARING', 'STORE_SAFE', 'BANK_ACCOUNT', 'PAYMENT_GATEWAY_CLEARING'))");
        }

        // 2. Tabel cash_bank_transactions (Subledger Mutasi Kas & Bank)
        Schema::create('cash_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->unsignedBigInteger('transfer_pair_account_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('transaction_number', 64);
            $table->string('transaction_type', 32); // RECEIPT, DISBURSEMENT, TRANSFER_OUT, TRANSFER_IN, POS_SHIFT_DEPOSIT, PG_SETTLEMENT, BANK_FEE, BANK_INTEREST
            $table->date('transaction_date');
            $table->decimal('amount', 18, 2);
            $table->decimal('running_balance_after', 18, 2);
            $table->text('description')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('idempotency_key', 128);
            $table->string('status', 32)->default('POSTED'); // DRAFT, POSTED, REVERSED
            $table->boolean('is_reconciled')->default(false);
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_cbt_tenant_id');
            $table->unique(['tenant_id', 'transaction_number'], 'uq_cbt_number');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_cbt_idempotency');

            $table->index(['tenant_id', 'cash_bank_account_id', 'is_reconciled', 'transaction_date'], 'idx_cbt_reconcile');
            $table->index(['tenant_id', 'status', 'transaction_date'], 'idx_cbt_status_date');

            // Foreign Keys
            $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_cbt_account')
                ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'transfer_pair_account_id'], 'fk_cbt_counterpart_account')
                ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_cbt_journal')
                ->references(['tenant_id', 'id'])->on('journal_entries')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'fk_cbt_store')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('created_by_user_id', 'fk_cbt_created_by')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('posted_by_user_id', 'fk_cbt_posted_by')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('reversed_by_user_id', 'fk_cbt_reversed_by')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE cash_bank_transactions ADD CONSTRAINT chk_cbt_transaction_type
                CHECK (transaction_type IN ('RECEIPT', 'DISBURSEMENT', 'TRANSFER_OUT', 'TRANSFER_IN', 'POS_SHIFT_DEPOSIT', 'PG_SETTLEMENT', 'BANK_FEE', 'BANK_INTEREST'))");

            DB::statement("ALTER TABLE cash_bank_transactions ADD CONSTRAINT chk_cbt_status
                CHECK (status IN ('DRAFT', 'POSTED', 'REVERSED'))");

            DB::statement('ALTER TABLE cash_bank_transactions ADD CONSTRAINT chk_cbt_amount
                CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_bank_transactions');
        Schema::dropIfExists('cash_bank_accounts');
    }
};
