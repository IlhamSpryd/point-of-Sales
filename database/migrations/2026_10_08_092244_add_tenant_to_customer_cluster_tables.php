<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tenantTables = ['customers', 'loyalty_tiers', 'customer_loyalty_accounts'];
        foreach ($tenantTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                if (! Schema::hasColumn($tbl, 'tenant_id')) {
                    Schema::table($tbl, function (Blueprint $table) {
                        $table->unsignedBigInteger('tenant_id')->nullable();
                    });
                }
                $indexesFound = array_column(Schema::getIndexes($tbl), 'name');
                $indexesFound = array_column(Schema::getIndexes($tbl), 'name');
                $indexName = "{$tbl}_tenant_id_index";
                if (! in_array($indexName, $indexesFound)) {
                    Schema::table($tbl, function (Blueprint $table) use ($indexName) {
                        $table->index('tenant_id', $indexName);
                    });
                }
            }
        }
    }

    public function down(): void
    {
        $tenantTables = ['customers', 'loyalty_tiers', 'customer_loyalty_accounts'];
        foreach ($tenantTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                $indexesFound = array_column(Schema::getIndexes($tbl), 'name');
                $indexesFound = array_column(Schema::getIndexes($tbl), 'name');
                $indexName = "{$tbl}_tenant_id_index";
                if (in_array($indexName, $indexesFound)) {
                    Schema::table($tbl, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }
                if (Schema::hasColumn($tbl, 'tenant_id')) {
                    Schema::table($tbl, function (Blueprint $table) {
                        $table->dropColumn('tenant_id');
                    });
                }
            }
        }
    }
};
