<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Implements DEC-001 Tier 2 baseline and Founder Architectural Decision 1:
     * - Adds is_default flag to companies table.
     * - Controlled, safe backfill: every existing tenant is assigned one default company.
     * - Warehouses without company_id are mapped to their tenant's default company.
     * - Historical transaction data is completely preserved.
     */
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (! Schema::hasColumn('companies', 'is_default')) {
                    $table->boolean('is_default')->default(false)->after('is_active');
                    $table->index(['tenant_id', 'is_default'], 'idx_companies_tenant_default');
                }
            });
        }

        // Backfill: Ensure every existing tenant has exactly one default company
        if (Schema::hasTable('tenants') && Schema::hasTable('companies')) {
            $tenants = DB::table('tenants')->get();

            foreach ($tenants as $tenant) {
                $existingCompany = DB::table('companies')
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('id')
                    ->first();

                if ($existingCompany) {
                    // If company exists, mark it as default if no default company exists for tenant
                    $hasDefault = DB::table('companies')
                        ->where('tenant_id', $tenant->id)
                        ->where('is_default', true)
                        ->exists();

                    if (! $hasDefault) {
                        DB::table('companies')
                            ->where('id', $existingCompany->id)
                            ->update(['is_default' => true]);
                    }
                    $companyId = $existingCompany->id;
                } else {
                    // Create default company for this tenant
                    $companyName = $tenant->name ?? 'Default Legal Entity';
                    $companyCode = 'CMP-'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT);

                    $companyId = DB::table('companies')->insertGetId([
                        'tenant_id' => $tenant->id,
                        'company_code' => $companyCode,
                        'company_name' => $companyName,
                        'legal_name' => $companyName,
                        'npwp' => null,
                        'tax_address' => null,
                        'is_active' => true,
                        'is_default' => true,
                        'created_at' => $tenant->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }

                // If warehouses exist for this tenant without company_id, associate them with default company
                // ONLY if the tenant has exactly one company (unambiguous ownership per Decision 1).
                // Do not infer company ownership when multiple companies exist.
                if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'company_id')) {
                    $companyCount = DB::table('companies')->where('tenant_id', $tenant->id)->count();
                    if ($companyCount === 1) {
                        DB::table('warehouses')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('company_id')
                            ->update(['company_id' => $companyId]);
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
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'is_default')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropIndex('idx_companies_tenant_default');
                $table->dropColumn('is_default');
            });
        }
    }
};
