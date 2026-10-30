<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('tax_identification_number', 255)->nullable();
            $table->string('ptkp_status', 16)->default('TK_0');
            $table->string('calculation_method', 16)->default('GROSS');
            $table->boolean('is_eligible_for_dtp')->default(false);
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id'], 'uq_emp_tax_profile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_profiles');
    }
};
