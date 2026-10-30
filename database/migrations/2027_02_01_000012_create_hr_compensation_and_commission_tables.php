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
        if (! Schema::hasTable('salary_components')) {
            Schema::create('salary_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('component_code', 50);
                $table->string('component_name', 100);
                $table->string('type', 30);
                $table->boolean('is_taxable')->default(true);
                $table->boolean('is_bpjs_kesehatan_subject')->default(true);
                $table->boolean('is_bpjs_tk_subject')->default(true);
                $table->boolean('is_fixed')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'component_code']);
            });
        }

        if (! Schema::hasTable('employee_compensations')) {
            Schema::create('employee_compensations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('salary_component_id')->constrained('salary_components')->cascadeOnDelete();
                $table->decimal('amount', 15, 2)->default(0.00);
                $table->date('effective_date');
                $table->date('end_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
                $table->timestamps();

                $table->index(['tenant_id', 'employee_id', 'effective_date', 'is_active'], 'idx_emp_comp_lookup');
            });
        }

        if (! Schema::hasTable('commission_rules')) {
            Schema::create('commission_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
                $table->string('rule_name', 100);
                $table->unsignedBigInteger('target_role_id')->nullable();
                $table->string('basis_type', 30);
                $table->decimal('rate_percent', 6, 4)->default(0.0000);
                $table->decimal('rate_amount', 15, 2)->default(0.00);
                $table->decimal('min_sales_threshold', 15, 2)->default(0.00);
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'store_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('commission_records')) {
            Schema::create('commission_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->decimal('calculation_basis_amount', 15, 2)->default(0.00);
                $table->decimal('commission_amount', 15, 2)->default(0.00);
                $table->string('type', 30)->default('STANDARD');
                $table->foreignId('reversal_source_id')->nullable()->constrained('commission_records')->nullOnDelete();
                $table->string('status', 30)->default('PENDING');
                $table->unsignedBigInteger('payroll_record_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'employee_id', 'status']);
                $table->index(['tenant_id', 'order_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_records');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('salary_components');
    }
};
