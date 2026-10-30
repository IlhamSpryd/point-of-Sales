<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_number', 32);
            $table->string('national_id_number', 255)->nullable();
            $table->string('full_name', 150);
            $table->string('nickname', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('phone_number', 32);
            $table->string('gender', 16);
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('religion', 32)->nullable();
            $table->string('marital_status', 32)->default('SINGLE');
            $table->text('residential_address')->nullable();
            $table->string('emergency_contact_name', 100)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('emergency_contact_relation', 50)->nullable();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('job_title_id')->constrained('job_titles')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employment_type', 32)->default('CONTRACT_PKWT');
            $table->string('employment_status', 32)->default('DRAFT');
            $table->date('hire_date');
            $table->date('probation_end_date')->nullable();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_number'], 'uq_employees_tenant_emp_num');
            $table->index(['tenant_id', 'employment_status'], 'idx_employees_status');
            $table->index(['tenant_id', 'hire_date'], 'idx_employees_hire_date');
            $table->index(['tenant_id', 'department_id'], 'idx_employees_department');
            $table->index(['tenant_id', 'job_title_id'], 'idx_employees_job_title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
