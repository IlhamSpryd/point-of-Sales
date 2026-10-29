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

        // 1. journal_entries
        Schema::create('journal_entries', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable(); // Tier 3B Hybrid
            $table->string('journal_number', 50);
            $table->unsignedBigInteger('accounting_period_id');
            $table->date('posting_date');
            $table->string('journal_type', 30)->default('SYSTEM_AUTO'); // SYSTEM_AUTO, MANUAL_ADJUSTMENT, REVERSAL, OPENING_BALANCE, CLOSING_ENTRY, DEPRECIATION
            $table->string('status', 30)->default('POSTED'); // DRAFT, PENDING_APPROVAL, POSTED, REVERSED, VOIDED_DRAFT
            $table->string('source_module', 50)->default('FINANCE');
            $table->string('source_type', 50)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number', 100)->nullable();
            $table->string('idempotency_key', 150);
            $table->unsignedBigInteger('posting_rule_version_id')->nullable();
            $table->unsignedBigInteger('reversal_of_journal_id')->nullable();
            $table->unsignedBigInteger('reversed_by_journal_id')->nullable();
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('total_debit', 18, 2)->default(0.00);
            $table->decimal('total_credit', 18, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_je_tenant_id');
            $table->unique(['tenant_id', 'journal_number'], 'uq_je_number');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_je_idempotency');
            $table->index(['tenant_id', 'accounting_period_id', 'status', 'posting_date'], 'idx_je_period_status');
            $table->index(['tenant_id', 'source_module', 'source_type', 'source_id'], 'idx_je_source');
            $table->index(['tenant_id', 'store_id', 'posting_date'], 'idx_je_store_date');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_je_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_je_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accounting_period_id'], 'fk_je_period')
                    ->references(['tenant_id', 'id'])
                    ->on('accounting_periods')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'reversal_of_journal_id'], 'fk_je_reversal_of')
                    ->references(['tenant_id', 'id'])
                    ->on('journal_entries')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'reversed_by_journal_id'], 'fk_je_reversed_by')
                    ->references(['tenant_id', 'id'])
                    ->on('journal_entries')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'posting_rule_version_id'], 'fk_je_rule_ver')
                    ->references(['tenant_id', 'id'])
                    ->on('posting_rule_versions')
                    ->onDelete('restrict');

                $table->foreign('created_by_user_id', 'fk_je_created_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');

                $table->foreign('approved_by_user_id', 'fk_je_approved_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');

                $table->foreign('posted_by_user_id', 'fk_je_posted_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        // 2. journal_lines
        Schema::create('journal_lines', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0.00);
            $table->decimal('credit_amount', 18, 2)->default(0.00);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_jl_tenant_id');
            $table->unique(['tenant_id', 'journal_entry_id', 'line_number'], 'uq_jl_entry_line');
            $table->index(['tenant_id', 'account_id', 'journal_entry_id'], 'idx_jl_account_lookup');
            $table->index(['tenant_id', 'store_id', 'account_id'], 'idx_jl_store_account');
            $table->index(['tenant_id', 'customer_id', 'supplier_id'], 'idx_jl_subledger_partner');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_jl_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_jl_entry')
                    ->references(['tenant_id', 'id'])
                    ->on('journal_entries')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'account_id'], 'fk_jl_account')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_jl_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'customer_id'], 'fk_jl_customer')
                    ->references(['tenant_id', 'id'])
                    ->on('customers')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'supplier_id'], 'fk_jl_supplier')
                    ->references(['tenant_id', 'id'])
                    ->on('suppliers')
                    ->onDelete('restrict');
            }
        });

        // 3. account_period_balances
        Schema::create('account_period_balances', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('store_scope_key')->default(0); // 0 for null store_id
            $table->decimal('opening_debit', 18, 2)->default(0.00);
            $table->decimal('opening_credit', 18, 2)->default(0.00);
            $table->decimal('period_debit', 18, 2)->default(0.00);
            $table->decimal('period_credit', 18, 2)->default(0.00);
            $table->decimal('closing_debit', 18, 2)->default(0.00);
            $table->decimal('closing_credit', 18, 2)->default(0.00);
            $table->decimal('net_movement', 18, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_apb_tenant_id');
            $table->unique(['tenant_id', 'accounting_period_id', 'account_id', 'store_scope_key'], 'uq_apb_scope');
            $table->index(['tenant_id', 'accounting_period_id', 'store_id'], 'idx_apb_period_store');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_apb_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accounting_period_id'], 'fk_apb_period')
                    ->references(['tenant_id', 'id'])
                    ->on('accounting_periods')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'account_id'], 'fk_apb_account')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_apb_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_period_balances');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
