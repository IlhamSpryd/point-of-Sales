<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target tables requiring Tier 2 legal entity (company_id) isolation per DEC-001.
     *
     * @var array<int, string>
     */
    private const TARGET_TABLES = [
        'stores',
        'employees',
        'payroll_batches',
        'journal_entries',
        'customer_invoices',
        'supplier_invoices',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add nullable company_id column and index to each table
        foreach (self::TARGET_TABLES as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'company_id')) {
                        $table->unsignedBigInteger('company_id')->nullable()->after('tenant_id');
                        $table->index(['tenant_id', 'company_id'], "idx_{$tableName}_tenant_company");
                    }
                });
            }
        }

        // 2. Ensure every tenant has at least one default company
        if (Schema::hasTable('tenants') && Schema::hasTable('companies')) {
            $tenants = DB::table('tenants')->get();
            foreach ($tenants as $tenant) {
                $hasCompany = DB::table('companies')
                    ->where('tenant_id', $tenant->id)
                    ->exists();

                if (! $hasCompany) {
                    $companyName = $tenant->name ?? 'Default Legal Entity';
                    $companyCode = 'CMP-'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT);
                    DB::table('companies')->insert([
                        'tenant_id' => $tenant->id,
                        'company_code' => $companyCode,
                        'company_name' => $companyName,
                        'legal_name' => $companyName,
                        'is_active' => true,
                        'is_default' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 3. Temporarily bypass journal entries immutability trigger for controlled schema backfill
            $hasJournalTrigger = false;
            if (DB::getDriverName() !== 'sqlite') {
                $hasJournalTrigger = DB::table('information_schema.triggers')
                    ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                    ->where('TRIGGER_NAME', 'trg_journal_entries_before_update')
                    ->exists();

                if ($hasJournalTrigger) {
                    DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_entries_before_update;');
                }
            }

            try {
                // Backfill company_id for all existing records in target tables
                foreach (self::TARGET_TABLES as $tableName) {
                    if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'company_id')) {
                        $tenantsWithRecords = DB::table($tableName)
                            ->select('tenant_id')
                            ->distinct()
                            ->pluck('tenant_id');

                        foreach ($tenantsWithRecords as $tenantId) {
                            $defaultCompany = DB::table('companies')
                                ->where('tenant_id', $tenantId)
                                ->orderByDesc('is_default')
                                ->orderBy('id')
                                ->first();

                            if ($defaultCompany) {
                                DB::table($tableName)
                                    ->where('tenant_id', $tenantId)
                                    ->whereNull('company_id')
                                    ->update(['company_id' => $defaultCompany->id]);
                            }
                        }
                    }
                }
            } finally {
                // Reinstate journal entries immutability trigger
                if ($hasJournalTrigger) {
                    DB::unprepared("
                        CREATE TRIGGER trg_journal_entries_before_update BEFORE UPDATE ON journal_entries
                        FOR EACH ROW
                        BEGIN
                            IF OLD.status IN ('POSTED', 'REVERSED') THEN
                                IF OLD.status = 'POSTED' AND NEW.status = 'REVERSED' THEN
                                    IF OLD.total_debit <> NEW.total_debit
                                       OR OLD.total_credit <> NEW.total_credit
                                       OR OLD.posting_date <> NEW.posting_date
                                       OR OLD.accounting_period_id <> NEW.accounting_period_id
                                       OR OLD.tenant_id <> NEW.tenant_id
                                       OR OLD.journal_number <> NEW.journal_number THEN
                                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi nilai finansial jurnal POSTED dilarang saat reversal.';
                                    END IF;
                                ELSE
                                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi jurnal POSTED/REVERSED dilarang. Gunakan jurnal pembalik (reversal).';
                                END IF;
                            END IF;
                        END;
                    ");
                }
            }
        }

        // 4. Add Composite Foreign Key constraints
        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::TARGET_TABLES as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'company_id')) {
                    try {
                        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                            $table->foreign(['tenant_id', 'company_id'], "fk_{$tableName}_company_id")
                                ->references(['tenant_id', 'id'])->on('companies')
                                ->onDelete('restrict')->onUpdate('cascade');
                        });
                    } catch (Throwable) {
                        // Skip if already applied or constraint exists
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TARGET_TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (DB::getDriverName() !== 'sqlite') {
                        try {
                            $table->dropForeign("fk_{$tableName}_company_id");
                        } catch (Throwable) {
                        }
                    }
                    $table->dropIndex("idx_{$tableName}_tenant_company");
                    $table->dropColumn('company_id');
                });
            }
        }
    }
};
