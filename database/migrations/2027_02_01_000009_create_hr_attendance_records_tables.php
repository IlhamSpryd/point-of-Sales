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
        if (! Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->nullOnDelete();
                $table->dateTime('clock_in_at');
                $table->dateTime('clock_out_at')->nullable();
                $table->string('source', 32)->default('POS_KIOSK');
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
                $table->string('status', 32)->default('PRESENT');
                $table->unsignedInteger('late_minutes')->default(0);
                $table->unsignedInteger('early_departure_minutes')->default(0);
                $table->unsignedInteger('total_break_minutes')->default(0);
                $table->unsignedInteger('net_work_minutes')->default(0);
                $table->boolean('is_locked')->default(false);
                $table->string('correction_reason', 255)->nullable();
                $table->foreignId('corrected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
                $table->string('snapshot_url', 255)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'store_id', 'clock_in_at', 'status'], 'idx_att_lookup');
                $table->index(['tenant_id', 'employee_id', 'clock_in_at'], 'idx_att_emp_date');
            });
        }

        if (! Schema::hasTable('attendance_breaks')) {
            Schema::create('attendance_breaks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
                $table->dateTime('break_start_at');
                $table->dateTime('break_end_at')->nullable();
                $table->unsignedInteger('duration_minutes')->default(0);
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'attendance_record_id'], 'idx_att_breaks_lookup');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_breaks');
        Schema::dropIfExists('attendance_records');
    }
};
