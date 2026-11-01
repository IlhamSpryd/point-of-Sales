<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Map 38 tabel target multi-tenant (Klaster 1 & Klaster 2) ke nama constraint foreign key tenant_id.
     *
     * @var array<string, string>
     */
    protected array $tableConstraints = [
        // Klaster 1: Finansial, Biaya Pokok (HPP), Pembelian & Penggajian (WAJIB RESTRICT)
        'product_cost_histories' => 'pch_tenant_id_foreign',
        'purchase_requests' => 'pr_tenant_fk',
        'purchase_request_lines' => 'pr_lines_tenant_fk',
        'purchase_approval_requests' => 'pareq_tenant_fk',
        'purchase_approval_logs' => 'palog_tenant_fk',
        'payroll_records' => 'payroll_records_tenant_id_foreign',
        'payroll_batches' => 'payroll_batches_tenant_id_foreign',
        'payroll_items' => 'payroll_items_tenant_id_foreign',
        'payroll_periods' => 'payroll_periods_tenant_id_foreign',
        'payroll_adjustments' => 'payroll_adjustments_tenant_id_foreign',
        'commission_records' => 'commission_records_tenant_id_foreign',
        'commission_rules' => 'commission_rules_tenant_id_foreign',
        'leave_transactions' => 'leave_transactions_tenant_id_foreign',
        'leave_balances' => 'leave_balances_tenant_id_foreign',
        'leave_requests' => 'leave_requests_tenant_id_foreign',
        'leave_policies' => 'leave_policies_tenant_id_foreign',
        'employee_compensations' => 'employee_compensations_tenant_id_foreign',
        'employee_tax_profiles' => 'employee_tax_profiles_tenant_id_foreign',
        'employee_bpjs_profiles' => 'employee_bpjs_profiles_tenant_id_foreign',
        'employee_bank_accounts' => 'employee_bank_accounts_tenant_id_foreign',

        // Klaster 2: Master Operasional & Struktur Organisasi
        'employees' => 'employees_tenant_id_foreign',
        'departments' => 'departments_tenant_id_foreign',
        'job_titles' => 'job_titles_tenant_id_foreign',
        'employee_branch_assignments' => 'employee_branch_assignments_tenant_id_foreign',
        'employee_employment_histories' => 'employee_employment_histories_tenant_id_foreign',
        'employee_id_sequences' => 'fk_eis_tenant',
        'attendance_records' => 'attendance_records_tenant_id_foreign',
        'attendance_breaks' => 'attendance_breaks_tenant_id_foreign',
        'overtime_records' => 'overtime_records_tenant_id_foreign',
        'work_schedules' => 'work_schedules_tenant_id_foreign',
        'shift_templates' => 'shift_templates_tenant_id_foreign',
        'suppliers' => 'suppliers_tenant_fk',
        'customer_groups' => 'customer_groups_tenant_fk',
        'brands' => 'brands_tenant_id_foreign',
        'units_of_measure' => 'uom_tenant_id_foreign',
        'tax_classes' => 'tax_classes_tenant_id_foreign',
        'devices' => 'devices_tenant_id_foreign',
        'document_sequences' => 'doc_sequences_tenant_fk',
    ];

    /**
     * Run the migrations: perketat aturan ON DELETE CASCADE menjadi ON DELETE RESTRICT.
     */
    public function up(): void
    {
        foreach ($this->tableConstraints as $tableName => $defaultConstraintName) {
            if (! DB::pretending()) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }

                $currentFk = $this->getTenantForeignKeyName($tableName) ?? $defaultConstraintName;
                $currentRule = $this->getDeleteRule($tableName, $currentFk);

                // Lewati jika sudah RESTRICT (idempoten)
                if ($currentRule === 'RESTRICT') {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($currentFk) {
                    $table->dropForeign($currentFk);
                });

                Schema::table($tableName, function (Blueprint $table) use ($currentFk) {
                    $table->foreign('tenant_id', $currentFk)
                        ->references('id')
                        ->on('tenants')
                        ->onDelete('restrict');
                });
            } else {
                Schema::table($tableName, function (Blueprint $table) use ($defaultConstraintName) {
                    $table->dropForeign($defaultConstraintName);
                });

                Schema::table($tableName, function (Blueprint $table) use ($defaultConstraintName) {
                    $table->foreign('tenant_id', $defaultConstraintName)
                        ->references('id')
                        ->on('tenants')
                        ->onDelete('restrict');
                });
            }
        }
    }

    /**
     * Reverse the migrations: kembalikan aturan menjadi ON DELETE CASCADE.
     */
    public function down(): void
    {
        foreach ($this->tableConstraints as $tableName => $defaultConstraintName) {
            if (! DB::pretending()) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }

                $currentFk = $this->getTenantForeignKeyName($tableName) ?? $defaultConstraintName;
                $currentRule = $this->getDeleteRule($tableName, $currentFk);

                // Lewati jika sudah CASCADE (idempoten)
                if ($currentRule === 'CASCADE') {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($currentFk) {
                    $table->dropForeign($currentFk);
                });

                Schema::table($tableName, function (Blueprint $table) use ($currentFk) {
                    $table->foreign('tenant_id', $currentFk)
                        ->references('id')
                        ->on('tenants')
                        ->onDelete('cascade');
                });
            } else {
                Schema::table($tableName, function (Blueprint $table) use ($defaultConstraintName) {
                    $table->dropForeign($defaultConstraintName);
                });

                Schema::table($tableName, function (Blueprint $table) use ($defaultConstraintName) {
                    $table->foreign('tenant_id', $defaultConstraintName)
                        ->references('id')
                        ->on('tenants')
                        ->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Mengambil nama constraint foreign key tenant_id yang aktif dari information_schema.
     */
    protected function getTenantForeignKeyName(string $tableName): ?string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $fk = DB::select(
                "SELECT CONSTRAINT_NAME 
                 FROM information_schema.KEY_COLUMN_USAGE 
                 WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = ? 
                   AND COLUMN_NAME = 'tenant_id' 
                   AND REFERENCED_TABLE_NAME = 'tenants'",
                [$tableName]
            );

            if (! empty($fk)) {
                return (string) $fk[0]->CONSTRAINT_NAME;
            }
        }

        return $this->tableConstraints[$tableName] ?? null;
    }

    /**
     * Memeriksa delete rule saat ini pada foreign key constraint.
     */
    protected function getDeleteRule(string $tableName, string $constraintName): ?string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return null;
        }

        $res = DB::select(
            "SELECT DELETE_RULE 
             FROM information_schema.REFERENTIAL_CONSTRAINTS 
             WHERE CONSTRAINT_SCHEMA = DATABASE() 
               AND TABLE_NAME = ? 
               AND CONSTRAINT_NAME = ?",
            [$tableName, $constraintName]
        );

        return ! empty($res) ? (string) $res[0]->DELETE_RULE : null;
    }
};
