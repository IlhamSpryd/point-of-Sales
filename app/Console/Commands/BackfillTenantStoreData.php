<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillTenantStoreData extends Command
{
    protected $signature = 'database:backfill-tenant-store {--apply : Execute the database writes}';

    protected $description = 'Backfill tenant_id and store_id for existing multi-tenant architecture safely';

    public function handle()
    {
        $apply = $this->option('apply');

        $this->info('Starting backfill for tenants and stores... '.($apply ? '[APPLY MODE]' : '[DRY RUN]'));

        $tenantCount = DB::table('tenants')->count();
        if ($tenantCount > 1) {
            $this->error("Abort: Ownership is ambiguous. Found $tenantCount tenants.");

            return 1;
        }

        $tenantId = null;
        $storeId = null;

        if ($tenantCount === 1) {
            $tenantId = DB::table('tenants')->value('id');
            $storeId = DB::table('stores')->where('tenant_id', $tenantId)->value('id');
            if (! $storeId && $apply) {
                $storeId = DB::table('stores')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => 'Main Store',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            if ($apply) {
                $tenantId = DB::table('tenants')->insertGetId([
                    'name' => 'Default Tenant',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $storeId = DB::table('stores')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => 'Main Store',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $tenantId = 1; // placeholder for dry run
                $storeId = 1; // placeholder for dry run
            }
        }

        $this->info("Target Tenant ID: $tenantId, Target Store ID: $storeId");

        if ($tenantId !== 1) {
            // Check legacy default = 1 in movement tables
            $movementTables = ['ingredient_stock_movements', 'stock_movements'];
            foreach ($movementTables as $tbl) {
                if (Schema::hasTable($tbl)) {
                    $hasOne = DB::table($tbl)->where('tenant_id', 1)->exists();
                    if ($hasOne) {
                        $this->error("STOP: $tbl holds the old default tenant_id = 1, but default tenant_id is $tenantId. Ledger triggers allow only NULL -> value.");

                        return 1;
                    }
                }
            }
        }

        $tables = [
            'roles', 'tables', 'modifier_groups', 'modifiers', 'modifier_group_product', 'modifier_ingredients',
            'product_ingredients', 'customers', 'loyalty_tiers', 'customer_loyalty_accounts', 'cash_drawer_movements',
            'activity_logs', 'settings', 'export_tasks', 'channel_order_logs', 'channel_product_mappings',
            'ingredient_restock_forecasts', 'user_stores',
            'users', 'products', 'categories', 'ingredients', 'shifts', 'orders', 'payments', 'discounts',
            'order_items', 'order_item_modifiers', 'stock_movements', 'ingredient_stock_movements', 'loyalty_ledger',
        ];

        $nullCountsBefore = [];
        foreach ($tables as $tbl) {
            if (! Schema::hasTable($tbl)) {
                continue;
            }
            $count = 0;
            if (Schema::hasColumn($tbl, 'tenant_id')) {
                $count += DB::table($tbl)->whereNull('tenant_id')->count();
            }
            if (Schema::hasColumn($tbl, 'store_id')) {
                $count += DB::table($tbl)->whereNull('store_id')->count();
            }
            $nullCountsBefore[$tbl] = $count;
            $this->line("Before - $tbl: $count NULLs");
        }

        if (! $apply) {
            $this->info('Dry run complete. Use --apply to execute.');

            return 0;
        }

        $this->backfillDirect($tables, $tenantId, $storeId);

        $nullCountsAfter = [];
        $remainingNulls = 0;
        foreach ($tables as $tbl) {
            if (! Schema::hasTable($tbl)) {
                continue;
            }
            $count = 0;
            if (Schema::hasColumn($tbl, 'tenant_id')) {
                $count += DB::table($tbl)->whereNull('tenant_id')->count();
            }
            if (Schema::hasColumn($tbl, 'store_id')) {
                $count += DB::table($tbl)->whereNull('store_id')->count();
            }
            $nullCountsAfter[$tbl] = $count;
            $remainingNulls += $count;
            $this->line("After - $tbl: $count NULLs remaining");
        }

        if ($remainingNulls > 0) {
            $this->error("Failed to backfill all rows. $remainingNulls NULLs remain.");

            return 1;
        }

        $this->info('Backfill completed successfully.');

        return 0;
    }

    private function backfillDirect(array $tables, $tenantId, $storeId)
    {
        // Parents first
        $parentOrder = [
            'users', 'categories', 'customers', 'roles', 'loyalty_tiers',
            'products', 'ingredients', 'modifiers', 'modifier_groups',
            'shifts', 'tables', 'orders',
        ];

        foreach ($parentOrder as $tbl) {
            if (! in_array($tbl, $tables) || ! Schema::hasTable($tbl)) {
                continue;
            }
            $this->chunkUpdate($tbl, $tenantId, $storeId);
        }

        // Dependent tables
        $deps = [
            'customer_loyalty_accounts' => ['parent' => 'customers', 'fk' => 'customer_id'],
            'order_items' => ['parent' => 'orders', 'fk' => 'order_id'],
            'payments' => ['parent' => 'orders', 'fk' => 'order_id'],
            'stock_movements' => ['parent' => 'orders', 'fk' => 'order_id'],
            'ingredient_stock_movements' => ['parent' => 'orders', 'fk' => 'order_id'],
            'loyalty_ledger' => ['parent' => 'orders', 'fk' => 'order_id'],
            'order_item_modifiers' => ['parent' => 'order_items', 'fk' => 'order_item_id'],
            'modifier_group_product' => ['parent' => 'products', 'fk' => 'product_id'],
            'modifier_ingredients' => ['parent' => 'modifiers', 'fk' => 'modifier_id'],
            'product_ingredients' => ['parent' => 'products', 'fk' => 'product_id'],
            'user_stores' => ['parent' => 'users', 'fk' => 'user_id'],
        ];

        foreach ($deps as $tbl => $info) {
            if (! in_array($tbl, $tables) || ! Schema::hasTable($tbl)) {
                continue;
            }

            $parentTable = $info['parent'];
            $fk = $info['fk'];
            $hasTenant = Schema::hasColumn($tbl, 'tenant_id');
            $hasStore = Schema::hasColumn($tbl, 'store_id');
            $parentHasStore = Schema::hasColumn($parentTable, 'store_id');

            $maxId = DB::table($tbl)->max('id');
            if (! $maxId) {
                continue;
            }

            for ($i = 0; $i <= $maxId; $i += 5000) {
                $end = $i + 4999;

                if ($hasTenant && $hasStore) {
                    $storeCol = $parentHasStore ? 'p.store_id' : '?';
                    $bindings = $parentHasStore ? [$tenantId, $storeId, $i, $end] : [$tenantId, $storeId, $storeId, $i, $end];

                    DB::statement("
                        UPDATE $tbl t
                        INNER JOIN $parentTable p ON t.$fk = p.id
                        SET t.tenant_id = COALESCE(p.tenant_id, ?),
                            t.store_id = COALESCE($storeCol, ?)
                        WHERE t.id BETWEEN ? AND ?
                        AND (t.tenant_id IS NULL OR t.store_id IS NULL)
                    ", $bindings);
                } elseif ($hasTenant) {
                    DB::statement("
                        UPDATE $tbl t
                        INNER JOIN $parentTable p ON t.$fk = p.id
                        SET t.tenant_id = COALESCE(p.tenant_id, ?)
                        WHERE t.id BETWEEN ? AND ?
                        AND t.tenant_id IS NULL
                    ", [$tenantId, $i, $end]);
                }
            }
        }

        // Remaining independent tables
        foreach ($tables as $tbl) {
            if (! Schema::hasTable($tbl)) {
                continue;
            }
            if (in_array($tbl, $parentOrder) || isset($deps[$tbl])) {
                continue;
            }
            $this->chunkUpdate($tbl, $tenantId, $storeId);
        }
    }

    private function chunkUpdate($tbl, $tenantId, $storeId)
    {
        $hasTenant = Schema::hasColumn($tbl, 'tenant_id');
        $hasStore = Schema::hasColumn($tbl, 'store_id');

        if (! $hasTenant && ! $hasStore) {
            return;
        }

        DB::table($tbl)->orderBy('id')->chunk(5000, function ($chunk) use ($tbl, $tenantId, $storeId, $hasTenant, $hasStore) {
            DB::transaction(function () use ($chunk, $tbl, $tenantId, $storeId, $hasTenant, $hasStore) {
                $idsToUpdate = [];
                foreach ($chunk as $row) {
                    if (($hasTenant && is_null($row->tenant_id)) || ($hasStore && is_null($row->store_id))) {
                        $idsToUpdate[] = $row->id;
                    }
                }

                if (! empty($idsToUpdate)) {
                    $upd = [];
                    if ($hasTenant) {
                        $upd['tenant_id'] = $tenantId;
                    }
                    if ($hasStore) {
                        $upd['store_id'] = $storeId;
                    }

                    DB::table($tbl)->whereIn('id', $idsToUpdate)->update($upd);
                }
            });
        });
    }
}
