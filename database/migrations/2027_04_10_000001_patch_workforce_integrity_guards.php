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
        if (Schema::hasTable('payroll_batches')) {
            Schema::table('payroll_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_batches', 'active_period_key')) {
                    $table->string('active_period_key', 64)->nullable()->after('payroll_period_id');
                    $table->unique(['tenant_id', 'active_period_key'], 'payroll_batches_tenant_active_period_unique');
                }
            });
        }

        if (Schema::hasTable('commission_records')) {
            Schema::table('commission_records', function (Blueprint $table) {
                if (! Schema::hasColumn('commission_records', 'payroll_batch_id')) {
                    $table->foreignId('payroll_batch_id')->nullable()->after('employee_id')->constrained('payroll_batches')->nullOnDelete();
                }
                if (! Schema::hasColumn('commission_records', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('status');
                }
                $table->index(['tenant_id', 'status', 'created_at'], 'comm_records_tenant_status_created_idx');
                $table->index(['payroll_batch_id'], 'comm_records_payroll_batch_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('commission_records')) {
            Schema::table('commission_records', function (Blueprint $table) {
                if (Schema::hasColumn('commission_records', 'payroll_batch_id')) {
                    $table->dropForeign(['payroll_batch_id']);
                    $table->dropColumn('payroll_batch_id');
                }
                if (Schema::hasColumn('commission_records', 'paid_at')) {
                    $table->dropColumn('paid_at');
                }
                $table->dropIndex('comm_records_tenant_status_created_idx');
                $table->dropIndex('comm_records_payroll_batch_idx');
            });
        }

        if (Schema::hasTable('payroll_batches')) {
            Schema::table('payroll_batches', function (Blueprint $table) {
                if (Schema::hasColumn('payroll_batches', 'active_period_key')) {
                    $table->dropUnique('payroll_batches_tenant_active_period_unique');
                    $table->dropColumn('active_period_key');
                }
            });
        }
    }
};
