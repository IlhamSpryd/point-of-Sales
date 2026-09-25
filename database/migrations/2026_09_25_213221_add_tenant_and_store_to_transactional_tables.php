<?php

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
        $bothTables = ['order_items', 'order_item_modifiers', 'loyalty_ledger'];
        foreach ($bothTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    if (!Schema::hasColumn($tbl, 'tenant_id')) {
                        $table->unsignedBigInteger('tenant_id')->nullable();
                    }
                    if (!Schema::hasColumn($tbl, 'store_id')) {
                        $table->unsignedBigInteger('store_id')->nullable();
                    }
                });
            }
        }

        if (Schema::hasTable('shifts')) {
            Schema::table('shifts', function (Blueprint $table) {
                if (!Schema::hasColumn('shifts', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $bothTables = ['order_items', 'order_item_modifiers', 'loyalty_ledger'];
        foreach ($bothTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    if (Schema::hasColumn($tbl, 'tenant_id')) {
                        $table->dropColumn('tenant_id');
                    }
                    if (Schema::hasColumn($tbl, 'store_id')) {
                        $table->dropColumn('store_id');
                    }
                });
            }
        }

        if (Schema::hasTable('shifts') && Schema::hasColumn('shifts', 'tenant_id')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
