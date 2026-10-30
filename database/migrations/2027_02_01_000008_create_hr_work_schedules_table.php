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
        if (! Schema::hasTable('work_schedules')) {
            Schema::create('work_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('shift_template_id')->constrained('shift_templates')->restrictOnDelete();
                $table->date('schedule_date');
                $table->string('status', 32)->default('SCHEDULED');
                $table->foreignId('swapped_with_schedule_id')->nullable()->constrained('work_schedules')->nullOnDelete();
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'employee_id', 'schedule_date'], 'uq_work_schedules_emp_date');
                $table->index(['tenant_id', 'store_id', 'schedule_date', 'status'], 'idx_work_schedules_store_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
