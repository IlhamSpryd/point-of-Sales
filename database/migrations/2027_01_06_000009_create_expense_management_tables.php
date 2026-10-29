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
        $driver = Schema::getConnection()->getDriverName();

        // 1. expense_categories
        Schema::create('expense_categories', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('category_code', 50);
            $table->string('category_name', 150);
            $table->unsignedBigInteger('expense_gl_account_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_ec_tenant_id');
            $table->unique(['tenant_id', 'category_code'], 'uq_ec_code');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_ec_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'expense_gl_account_id'], 'fk_ec_gl_acc')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');
            }
        });

        // 2. expenses
        Schema::create('expenses', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->string('expense_number', 64);
            $table->date('expense_date');
            $table->decimal('subtotal_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->decimal('total_amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('DRAFT'); // DRAFT, SUBMITTED, APPROVED, REJECTED, PAID, VOIDED
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('cash_bank_account_id')->nullable();
            $table->unsignedBigInteger('cash_bank_transaction_id')->nullable();
            $table->unsignedBigInteger('accrual_journal_id')->nullable();
            $table->unsignedBigInteger('payment_journal_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_exp_tenant_id');
            $table->unique(['tenant_id', 'expense_number'], 'uq_exp_number');
            $table->index(['tenant_id', 'status', 'expense_date'], 'idx_exp_status_date');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'category_id'], 'fk_exp_category')
                    ->references(['tenant_id', 'id'])
                    ->on('expense_categories')
                    ->onDelete('restrict');

                $table->foreign('submitted_by_user_id', 'fk_exp_submitted_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');

                $table->foreign('approved_by_user_id', 'fk_exp_approved_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_exp_cba')
                    ->references(['tenant_id', 'id'])
                    ->on('cash_bank_accounts')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'cash_bank_transaction_id'], 'fk_exp_cbt')
                    ->references(['tenant_id', 'id'])
                    ->on('cash_bank_transactions')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accrual_journal_id'], 'fk_exp_accrual_journal')
                    ->references(['tenant_id', 'id'])
                    ->on('journal_entries')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'payment_journal_id'], 'fk_exp_payment_journal')
                    ->references(['tenant_id', 'id'])
                    ->on('journal_entries')
                    ->onDelete('restrict');
            }
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT chk_exp_status
                CHECK (status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'PAID', 'VOIDED'))");

            DB::statement('ALTER TABLE expenses ADD CONSTRAINT chk_exp_amounts
                CHECK (subtotal_amount >= 0.00 AND tax_amount >= 0.00 AND total_amount > 0.00)');
        }

        // 3. expense_lines
        Schema::create('expense_lines', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('expense_id');
            $table->unsignedInteger('line_number');
            $table->string('description', 255);
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 18, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_el_tenant_id');
            $table->unique(['expense_id', 'line_number'], 'uq_el_line');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'expense_id'], 'fk_el_expense')
                    ->references(['tenant_id', 'id'])
                    ->on('expenses')
                    ->onDelete('cascade');

                $table->foreign(['tenant_id', 'tax_code_id'], 'fk_el_tax_code')
                    ->references(['tenant_id', 'id'])
                    ->on('tax_codes')
                    ->onDelete('restrict');
            }
        });

        // 4. expense_attachments
        Schema::create('expense_attachments', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('expense_id');
            $table->text('file_path');
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_ea_tenant_id');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'expense_id'], 'fk_ea_expense')
                    ->references(['tenant_id', 'id'])
                    ->on('expenses')
                    ->onDelete('cascade');
            }
        });

        // 5. expense_approvals
        Schema::create('expense_approvals', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('approver_user_id');
            $table->string('action', 32); // APPROVED, REJECTED
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_eapp_tenant_id');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'expense_id'], 'fk_eapp_expense')
                    ->references(['tenant_id', 'id'])
                    ->on('expenses')
                    ->onDelete('cascade');

                $table->foreign('approver_user_id', 'fk_eapp_approver')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE expense_approvals ADD CONSTRAINT chk_eapp_action
                CHECK (action IN ('APPROVED', 'REJECTED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approvals');
        Schema::dropIfExists('expense_attachments');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
