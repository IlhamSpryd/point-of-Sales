<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('leave_policies')) {
            Schema::create('leave_policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('policy_code', 50);
                $table->string('policy_name', 100);
                $table->boolean('is_paid')->default(true);
                $table->decimal('default_days_per_year', 5, 2)->default(0.00);
                $table->boolean('is_gender_specific')->default(false);
                $table->string('applicable_gender', 20)->nullable();
                $table->boolean('requires_attachment')->default(false);
                $table->string('expiration_policy', 50)->default('FORFEIT');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'policy_code']);
            });
        }

        if (! Schema::hasTable('leave_balances')) {
            Schema::create('leave_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('leave_policy_id')->constrained('leave_policies')->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->decimal('allocated_days', 5, 2)->default(0.00);
                $table->decimal('used_days', 5, 2)->default(0.00);
                $table->decimal('remaining_days', 5, 2)->default(0.00);
                $table->decimal('expired_days', 5, 2)->default(0.00);
                $table->timestamps();

                $table->unique(['tenant_id', 'employee_id', 'leave_policy_id', 'year'], 'uniq_leave_balance');
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('leave_policy_id')->constrained('leave_policies')->cascadeOnDelete();
                $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('total_days', 5, 2);
                $table->string('reason', 255);
                $table->string('attachment_path', 255)->nullable();
                $table->string('status', 30)->default('PENDING');
                $table->timestamps();

                $table->index(['tenant_id', 'employee_id', 'status']);
            });
        }

        if (! Schema::hasTable('leave_transactions')) {
            Schema::create('leave_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('leave_policy_id')->constrained('leave_policies')->cascadeOnDelete();
                $table->string('transaction_type', 30);
                $table->decimal('days_delta', 5, 2);
                $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();
                $table->string('reason', 255);
                $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['tenant_id', 'employee_id', 'leave_policy_id', 'created_at'], 'idx_leave_tx_ledger');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_transactions');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_policies');
    }
};
