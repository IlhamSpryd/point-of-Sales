<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_bpjs_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('bpjs_ketenagakerjaan_number', 255)->nullable();
            $table->string('bpjs_kesehatan_number', 255)->nullable();
            $table->unsignedTinyInteger('jkk_risk_grade')->default(1);
            $table->boolean('is_bpjs_tk_active')->default(true);
            $table->boolean('is_bpjs_ks_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id'], 'uq_emp_bpjs_profile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bpjs_profiles');
    }
};
