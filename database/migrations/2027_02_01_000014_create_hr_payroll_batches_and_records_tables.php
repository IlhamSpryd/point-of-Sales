<?php

declare(strict_types=1);

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
        if (! Schema::hasTable('payroll_periods')) {
            Schema::create('payroll_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('period_name', 100);
                $table->date('start_date');
                $table->date('end_date');
                $table->date('cutoff_date');
                $table->date('payment_date');
                $table->boolean('is_closed')->default(false);
                $table->timestamps();

                $table->unique(['tenant_id', 'start_date', 'end_date'], 'uniq_tenant_payroll_period');
            });
        }

        if (! Schema::hasTable('payroll_batches')) {
            Schema::create('payroll_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('batch_number', 64);
                $table->foreignId('payroll_period_id')->nullable()->constrained('payroll_periods')->nullOnDelete();
                $table->date('period_start_date');
                $table->date('period_end_date');
                $table->date('cutoff_date');
                $table->date('payment_scheduled_date');
                $table->string('status', 30)->default('DRAFT');
                $table->unsignedInteger('total_employees')->default(0);
                $table->decimal('total_gross_amount', 15, 2)->default(0.00);
                $table->decimal('total_tax_amount', 15, 2)->default(0.00);
                $table->decimal('total_bpjs_employer', 15, 2)->default(0.00);
                $table->decimal('total_bpjs_employee', 15, 2)->default(0.00);
                $table->decimal('total_other_deductions', 15, 2)->default(0.00);
                $table->decimal('total_net_amount', 15, 2)->default(0.00);
                $table->unsignedBigInteger('gl_accrual_journal_id')->nullable();
                $table->unsignedBigInteger('gl_payment_journal_id')->nullable();
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('finalized_at')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'batch_number'], 'uq_payroll_batches_num');
                $table->index(['tenant_id', 'status', 'period_end_date'], 'idx_pay_batches_status');
            });
        }

        if (! Schema::hasTable('payroll_records')) {
            Schema::create('payroll_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('payroll_batch_id')->constrained('payroll_batches')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

                // Self-contained snapshots
                $table->string('employee_number_snapshot', 32);
                $table->string('full_name_snapshot', 150);
                $table->string('department_name_snapshot', 100);
                $table->string('job_title_snapshot', 100);
                $table->unsignedBigInteger('primary_store_id');
                $table->string('primary_store_name_snapshot', 100);
                $table->string('ptkp_status_snapshot', 10);
                $table->string('tax_calculation_method_snapshot', 20);
                $table->string('bank_name_snapshot', 50)->default('');
                $table->string('bank_account_number_snapshot', 50)->default('');

                // Financial totals
                $table->decimal('basic_salary_amount', 15, 2)->default(0.00);
                $table->decimal('fixed_allowances_amount', 15, 2)->default(0.00);
                $table->decimal('variable_allowances_amount', 15, 2)->default(0.00);
                $table->decimal('overtime_amount', 15, 2)->default(0.00);
                $table->decimal('commissions_amount', 15, 2)->default(0.00);
                $table->decimal('gross_earnings_amount', 15, 2)->default(0.00);
                $table->decimal('taxable_gross_amount', 15, 2)->default(0.00);
                $table->decimal('tax_pph21_amount', 15, 2)->default(0.00);
                $table->decimal('tax_pph21_dtp_amount', 15, 2)->default(0.00);
                $table->decimal('bpjs_ks_employer_amount', 15, 2)->default(0.00);
                $table->decimal('bpjs_ks_employee_amount', 15, 2)->default(0.00);
                $table->decimal('bpjs_tk_employer_amount', 15, 2)->default(0.00);
                $table->decimal('bpjs_tk_employee_amount', 15, 2)->default(0.00);
                $table->decimal('other_deductions_amount', 15, 2)->default(0.00);
                $table->decimal('net_pay_amount', 15, 2)->default(0.00);
                $table->string('payslip_token', 64)->unique();
                $table->timestamps();

                $table->unique(['tenant_id', 'payroll_batch_id', 'employee_id'], 'uq_payroll_records_batch_emp');
                $table->index(['tenant_id', 'employee_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('payroll_items')) {
            Schema::create('payroll_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('payroll_record_id')->constrained('payroll_records')->cascadeOnDelete();
                $table->string('component_code', 50);
                $table->string('component_name', 100);
                $table->string('type', 30);
                $table->decimal('amount', 15, 2)->default(0.00);
                $table->boolean('is_taxable')->default(true);
                $table->unsignedBigInteger('statutory_rule_version_id')->nullable();
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'payroll_record_id'], 'idx_pay_items_rec');
            });
        }

        if (! Schema::hasTable('payroll_adjustments')) {
            Schema::create('payroll_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('payroll_batch_id')->nullable()->constrained('payroll_batches')->nullOnDelete();
                $table->string('adjustment_type', 30);
                $table->decimal('amount', 15, 2);
                $table->string('reason', 255);
                $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->index(['tenant_id', 'employee_id'], 'idx_pay_adj_emp');
            });
        }

        // Trigger Imutabilitas pada payroll_records
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_records_immutable_update');
        DB::unprepared("
            CREATE TRIGGER trg_payroll_records_immutable_update BEFORE UPDATE ON payroll_records
            FOR EACH ROW
            BEGIN
                DECLARE v_batch_status VARCHAR(20);
                SELECT status INTO v_batch_status FROM payroll_batches WHERE id = OLD.payroll_batch_id;
                IF v_batch_status IN ('FINALIZED', 'PAYMENT_PENDING', 'PAID') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL-001: Cannot update payroll record once batch is FINALIZED or PAID.';
                END IF;
            END
        ");

        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_records_immutable_delete');
        DB::unprepared("
            CREATE TRIGGER trg_payroll_records_immutable_delete BEFORE DELETE ON payroll_records
            FOR EACH ROW
            BEGIN
                DECLARE v_batch_status VARCHAR(20);
                SELECT status INTO v_batch_status FROM payroll_batches WHERE id = OLD.payroll_batch_id;
                IF v_batch_status IN ('FINALIZED', 'PAYMENT_PENDING', 'PAID') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL-001: Cannot delete payroll record once batch is FINALIZED or PAID.';
                END IF;
            END
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_records_immutable_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_records_immutable_delete');

        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('payroll_batches');
        Schema::dropIfExists('payroll_periods');
    }
};
