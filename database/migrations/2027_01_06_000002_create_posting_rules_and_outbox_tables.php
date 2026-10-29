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

        // 1. posting_rules
        Schema::create('posting_rules', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_code', 100); // e.g. pos.order.paid, purchasing.grn.posted
            $table->string('rule_name', 150);
            $table->string('source_domain', 50); // SALES, POS, PURCHASING, INVENTORY, EXPENSE, FIXED_ASSETS, CASH_BANK
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('approval_status', 30)->default('APPROVED'); // DRAFT, APPROVED, ARCHIVED
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_posting_rules_tenant_id');
            $table->unique(['tenant_id', 'event_code'], 'uq_posting_rules_event');
            $table->index(['tenant_id', 'source_domain', 'is_active'], 'idx_posting_rules_domain');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_posting_rules_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign('approved_by_user_id', 'fk_posting_rules_approved_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        // 2. posting_rule_versions
        Schema::create('posting_rule_versions', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('posting_rule_id');
            $table->unsignedInteger('version_number')->default(1);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 30)->default('ACTIVE'); // DRAFT, ACTIVE, SUPERSEDED
            $table->text('change_summary')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_prv_tenant_id');
            $table->unique(['tenant_id', 'posting_rule_id', 'version_number'], 'uq_prv_version');
            $table->index(['tenant_id', 'posting_rule_id', 'effective_from', 'effective_to', 'status'], 'idx_prv_effective');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_prv_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'posting_rule_id'], 'fk_prv_rule')
                    ->references(['tenant_id', 'id'])
                    ->on('posting_rules')
                    ->onDelete('restrict');

                $table->foreign('created_by_user_id', 'fk_prv_created_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            }
        });

        // 3. posting_rule_lines
        Schema::create('posting_rule_lines', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('posting_rule_version_id');
            $table->unsignedInteger('line_sequence');
            $table->string('side', 10); // DEBIT, CREDIT
            $table->string('amount_source_key', 100); // e.g. gross_sales_amount, tax_amount, cogs_amount
            $table->string('account_resolution_strategy', 50); // FIXED_ACCOUNT, DYNAMIC_BY_PAYMENT_METHOD, etc.
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('system_account_key', 100)->nullable();
            $table->string('zero_amount_behavior', 20)->default('SKIP_LINE'); // SKIP_LINE, FAIL_POSTING
            $table->string('description_template', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_prl_tenant_id');
            $table->unique(['tenant_id', 'posting_rule_version_id', 'line_sequence'], 'uq_prl_line');
            $table->index(['tenant_id', 'account_id'], 'idx_prl_account');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_prl_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'posting_rule_version_id'], 'fk_prl_version')
                    ->references(['tenant_id', 'id'])
                    ->on('posting_rule_versions')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'account_id'], 'fk_prl_account')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');
            }
        });

        // 4. financial_event_outboxes
        Schema::create('financial_event_outboxes', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('event_id', 100);
            $table->string('event_type', 100);
            $table->string('source_module', 50);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 100)->nullable();
            $table->string('idempotency_key', 150);
            $table->json('payload');
            $table->string('status', 30)->default('PENDING'); // PENDING, PROCESSING, PROCESSED, FAILED, DEAD_LETTER
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_feo_tenant_id');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_feo_idempotency');
            $table->index(['status', 'next_retry_at', 'id'], 'idx_feo_worker_poll');
            $table->index(['tenant_id', 'source_module', 'source_type', 'source_id'], 'idx_feo_source');

            if ($driver === 'mysql') {
                $table->foreign('tenant_id', 'fk_feo_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_feo_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_event_outboxes');
        Schema::dropIfExists('posting_rule_lines');
        Schema::dropIfExists('posting_rule_versions');
        Schema::dropIfExists('posting_rules');
    }
};
