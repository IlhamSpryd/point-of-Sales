<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DEC-001 & ADR-105: Remediate Composite Foreign Keys and Enforce UNIQUE(tenant_id, id)
     */
    public function up(): void
    {
        // 1. Remediate devices FK -> operational_units(tenant_id, id)
        if (Schema::hasTable('devices') && Schema::hasTable('operational_units')) {
            $hasSingleColumnFk = DB::select("
                SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'devices'
                  AND CONSTRAINT_NAME = 'fk_devices_operational_unit'
                  AND COLUMN_NAME = 'operational_unit_id'
                  AND NOT EXISTS (
                      SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'devices'
                        AND CONSTRAINT_NAME = 'fk_devices_operational_unit'
                        AND COLUMN_NAME = 'tenant_id'
                  )
            ");

            if (! empty($hasSingleColumnFk)) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->dropForeign('fk_devices_operational_unit');
                });
            }

            $hasOldDevicesIndex = DB::select("
                SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'devices'
                  AND INDEX_NAME = 'fk_devices_operational_unit'
            ");
            if (! empty($hasOldDevicesIndex)) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->dropIndex('fk_devices_operational_unit');
                });
            }

            $hasCompositeFk = DB::select("
                SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'devices'
                  AND CONSTRAINT_NAME = 'fk_devices_operational_unit'
                  AND COLUMN_NAME = 'tenant_id'
            ");

            if (empty($hasCompositeFk)) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'operational_unit_id'], 'fk_devices_operational_unit')
                        ->references(['tenant_id', 'id'])
                        ->on('operational_units')
                        ->onUpdate('cascade')
                        ->onDelete('restrict');
                });
            }
        }

        // 2. Remediate order_items FK -> units_of_measure(tenant_id, id)
        if (Schema::hasTable('order_items') && Schema::hasTable('units_of_measure')) {
            $hasSingleColumnFk = DB::select("
                SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'order_items'
                  AND CONSTRAINT_NAME = 'order_items_uom_fk'
                  AND COLUMN_NAME = 'uom_id'
                  AND NOT EXISTS (
                      SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'order_items'
                        AND CONSTRAINT_NAME = 'order_items_uom_fk'
                        AND COLUMN_NAME = 'tenant_id'
                  )
            ");

            if (! empty($hasSingleColumnFk)) {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->dropForeign('order_items_uom_fk');
                });
            }

            $hasOldOrderItemsIndex = DB::select("
                SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'order_items'
                  AND INDEX_NAME = 'order_items_uom_fk'
            ");
            if (! empty($hasOldOrderItemsIndex)) {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->dropIndex('order_items_uom_fk');
                });
            }

            $hasCompositeFk = DB::select("
                SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'order_items'
                  AND CONSTRAINT_NAME = 'order_items_uom_fk'
                  AND COLUMN_NAME = 'tenant_id'
            ");

            if (empty($hasCompositeFk)) {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->foreign(['tenant_id', 'uom_id'], 'order_items_uom_fk')
                        ->references(['tenant_id', 'id'])
                        ->on('units_of_measure')
                        ->onUpdate('cascade')
                        ->onDelete('restrict');
                });
            }
        }

        // 3. Enforce composite UNIQUE(tenant_id, id) on the 8 regular tenant tables
        $tablesWithUniqueNeeded = [
            'store_targets' => 'store_targets_tenant_id_id_unique',
            'tenant_capabilities' => 'tenant_capabilities_tenant_id_id_unique',
            'user_store_roles' => 'user_store_roles_tenant_id_id_unique',
            'store_capabilities' => 'store_capabilities_tenant_id_id_unique',
            'whatsapp_message_outbox' => 'whatsapp_message_outbox_tenant_id_id_unique',
            'currencies' => 'currencies_tenant_id_id_unique',
            'exchange_rates' => 'exchange_rates_tenant_id_id_unique',
            'payment_inbox' => 'payment_inbox_tenant_id_id_unique',
        ];

        foreach ($tablesWithUniqueNeeded as $tableName => $indexName) {
            if (Schema::hasTable($tableName)) {
                $hasCompositeUnique = DB::select("
                    SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND NON_UNIQUE = 0
                    GROUP BY INDEX_NAME
                    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'tenant_id,id'
                ", [$tableName]);

                if (empty($hasCompositeUnique)) {
                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->unique(['tenant_id', 'id'], $indexName);
                    });
                }
            }
        }

        // 4. Rescope channel_order_logs unique constraint to include tenant_id
        if (Schema::hasTable('channel_order_logs')) {
            $hasOldNonTenantUnique = DB::select("
                SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'channel_order_logs'
                  AND INDEX_NAME = 'unique_provider_order'
            ");

            if (! empty($hasOldNonTenantUnique)) {
                Schema::table('channel_order_logs', function (Blueprint $table) {
                    $table->dropUnique('unique_provider_order');
                });
            }

            $hasTenantRescopedUnique = DB::select("
                SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'channel_order_logs'
                  AND NON_UNIQUE = 0
                GROUP BY INDEX_NAME
                HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'tenant_id,provider,external_order_id'
            ");

            if (empty($hasTenantRescopedUnique)) {
                Schema::table('channel_order_logs', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'provider', 'external_order_id'], 'channel_order_logs_tenant_id_provider_ext_order_id_unique');
                });
            }
        }
    }

    public function down(): void
    {
        // Safe rollback operations
    }
};
