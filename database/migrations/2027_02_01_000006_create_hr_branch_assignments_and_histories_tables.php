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
        if (! Schema::hasTable('employee_branch_assignments')) {
            Schema::create('employee_branch_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('assignment_type', 32)->default('PRIMARY');
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'employee_id', 'store_id', 'assignment_type'], 'idx_emp_branch_lookup');
                $table->index(['tenant_id', 'store_id', 'assignment_type', 'valid_from', 'valid_to'], 'idx_emp_branch_active');
            });
        }

        if (! Schema::hasTable('employee_employment_histories')) {
            Schema::create('employee_employment_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId('from_job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
                $table->foreignId('to_job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
                $table->date('effective_date');
                $table->string('reference_number', 100)->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['tenant_id', 'employee_id', 'effective_date'], 'idx_emp_hist_timeline');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_employment_histories');
        Schema::dropIfExists('employee_branch_assignments');
    }
};
