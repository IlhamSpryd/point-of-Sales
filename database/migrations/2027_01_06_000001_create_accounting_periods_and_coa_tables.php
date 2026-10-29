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

        // 1. accounting_periods
        Schema::create('accounting_periods', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('period_code', 20); // e.g. 2026-10, 2026-ADJ
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period_number'); // 1..12 or 13 for adjustment
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('OPEN'); // OPEN, SOFT_CLOSED, CLOSED, LOCKED
            $table->boolean('is_adjustment_period')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by_user_id')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_acc_periods_tenant_id');
            $table->unique(['tenant_id', 'period_code'], 'uq_acc_periods_code');
            $table->unique(['tenant_id', 'start_date', 'end_date'], 'uq_acc_periods_dates');
            $table->index(['tenant_id', 'status', 'start_date', 'end_date'], 'idx_acc_periods_lookup');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_acc_periods_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign('closed_by_user_id', 'fk_acc_periods_closed_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');

                $table->foreign('reopened_by_user_id', 'fk_acc_periods_reopened_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        // 2. chart_of_accounts
        Schema::create('chart_of_accounts', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('account_code', 50);
            $table->string('account_name', 150);
            $table->string('account_type', 30); // ASSET, LIABILITY, EQUITY, REVENUE, COGS, EXPENSE, OTHER_INCOME, OTHER_EXPENSE
            $table->string('account_subtype', 50); // CURRENT_ASSET, CASH_BANK, RECEIVABLE, INVENTORY, etc.
            $table->string('normal_balance', 10); // DEBIT, CREDIT
            $table->string('system_account_key', 100)->nullable(); // unique anchor per tenant
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_control_account')->default(false);
            $table->boolean('allow_manual_journal')->default(true);
            $table->boolean('is_reconcilable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_coa_tenant_id');
            $table->unique(['tenant_id', 'account_code'], 'uq_coa_code');
            $table->unique(['tenant_id', 'system_account_key'], 'uq_coa_system_key');
            $table->index(['tenant_id', 'account_type', 'is_active', 'is_postable'], 'idx_coa_type_active');
            $table->index(['tenant_id', 'parent_id'], 'idx_coa_parent');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_coa_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'parent_id'], 'fk_coa_parent')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');

                $table->foreign('created_by', 'fk_coa_created_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');

                $table->foreign('updated_by', 'fk_coa_updated_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        // 3. period_closing_checklists
        Schema::create('period_closing_checklists', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->string('gate_code', 50);
            $table->string('gate_name', 150);
            $table->string('status', 30)->default('PENDING'); // PENDING, PASSED, FAILED, WAIVED_BY_DIRECTOR
            $table->decimal('discrepancy_amount', 18, 2)->default(0.00);
            $table->json('diagnostics_json')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedBigInteger('checked_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_pcc_tenant_id');
            $table->unique(['tenant_id', 'accounting_period_id', 'gate_code'], 'uq_pcc_period_gate');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_pcc_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accounting_period_id'], 'fk_pcc_period')
                    ->references(['tenant_id', 'id'])
                    ->on('accounting_periods')
                    ->onDelete('restrict');

                $table->foreign('checked_by_user_id', 'fk_pcc_checked_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        // 4. period_closing_logs
        Schema::create('period_closing_logs', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->string('action', 30); // SOFT_CLOSE, HARD_CLOSE, LOCK, REOPEN, YEAR_END_CLOSE
            $table->unsignedBigInteger('user_id');
            $table->text('reason')->nullable();
            $table->string('previous_status', 20);
            $table->string('new_status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_pcl_tenant_id');
            $table->index(['tenant_id', 'accounting_period_id', 'created_at'], 'idx_pcl_period');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_pcl_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accounting_period_id'], 'fk_pcl_period')
                    ->references(['tenant_id', 'id'])
                    ->on('accounting_periods')
                    ->onDelete('restrict');

                $table->foreign('user_id', 'fk_pcl_user')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_closing_logs');
        Schema::dropIfExists('period_closing_checklists');
        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('accounting_periods');
    }
};
