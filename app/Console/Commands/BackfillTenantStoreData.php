<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('database:backfill-tenant-store')]
#[Description('Backfill tenant_id and store_id for existing multi-tenant architecture')]
class BackfillTenantStoreData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill for tenants and stores...');

        DB::transaction(function () {
            // 1. Create Default Tenant
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Default Tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Create Default Store
            $storeId = DB::table('stores')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Main Store',
                'address' => 'Default Address',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info("Created Default Tenant (ID: $tenantId) and Store (ID: $storeId)");

            // 3. Backfill tenant_id
            $tenantTables = ['users', 'products', 'categories', 'ingredients'];
            foreach ($tenantTables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table) && DB::getSchemaBuilder()->hasColumn($table, 'tenant_id')) {
                    $affected = DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    $this->line("Updated $affected rows in $table (tenant_id)");
                }
            }

            // 4. Backfill store_id
            $storeTables = ['shifts'];
            foreach ($storeTables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table) && DB::getSchemaBuilder()->hasColumn($table, 'store_id')) {
                    $affected = DB::table($table)->whereNull('store_id')->update(['store_id' => $storeId]);
                    $this->line("Updated $affected rows in $table (store_id)");
                }
            }

            // 5. Backfill both
            $bothTables = ['orders', 'payments', 'discounts'];
            foreach ($bothTables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $affected = 0;
                    if (DB::getSchemaBuilder()->hasColumn($table, 'tenant_id')) {
                        $affected = DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    }
                    if (DB::getSchemaBuilder()->hasColumn($table, 'store_id')) {
                        DB::table($table)->whereNull('store_id')->update(['store_id' => $storeId]);
                    }
                    $this->line("Updated $affected rows in $table (tenant_id & store_id)");
                }
            }
        });

        $this->info('Backfill completed successfully.');
    }
}
