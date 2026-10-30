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
        if (! Schema::hasTable('overtime_records')) {
            Schema::create('overtime_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
                $table->foreignId('approval_id')->constrained('approvals')->restrictOnDelete();
                $table->date('overtime_date');
                $table->string('day_type', 32)->default('WORKDAY');
                $table->dateTime('start_time');
                $table->dateTime('end_time');
                $table->unsignedInteger('actual_duration_minutes');
                $table->decimal('payable_hours', 6, 2);
                $table->decimal('hourly_rate_basis', 15, 2);
                $table->decimal('calculated_amount', 15, 2);
                $table->string('status', 32)->default('PENDING');
                $table->timestamps();

                $table->index(['tenant_id', 'store_id', 'overtime_date', 'status'], 'idx_ot_lookup');
                $table->index(['tenant_id', 'employee_id', 'overtime_date'], 'idx_ot_emp_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
    }
};
