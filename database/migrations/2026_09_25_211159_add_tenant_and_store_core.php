<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create tenants table
        if (!Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 2. Create stores table
        if (!Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name');
                $table->string('address')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Add tenant_id to specific tables
        $tenantTables = ['users', 'products', 'categories', 'ingredients'];
        foreach ($tenantTables as $tbl) {
            if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'tenant_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable();
                });
            }
        }

        // 4. Add store_id to specific tables
        $storeTables = ['shifts'];
        foreach ($storeTables as $tbl) {
            if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'store_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->unsignedBigInteger('store_id')->nullable();
                });
            }
        }

        // 5. Add both tenant_id and store_id to specific tables
        $bothTables = ['orders', 'payments', 'discounts'];
        foreach ($bothTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) {
                    if (!Schema::hasColumn($table->getTable(), 'tenant_id')) {
                        $table->unsignedBigInteger('tenant_id')->nullable();
                    }
                    if (!Schema::hasColumn($table->getTable(), 'store_id')) {
                        $table->unsignedBigInteger('store_id')->nullable();
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $bothTables = ['orders', 'payments', 'discounts'];
        foreach ($bothTables as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropColumn(['tenant_id', 'store_id']);
            });
        }

        $storeTables = ['shifts', 'pos_devices', 'inventory_ledgers'];
        foreach ($storeTables as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropColumn('store_id');
            });
        }

        $tenantTables = ['users', 'products', 'categories', 'ingredients', 'suppliers', 'recipes'];
        foreach ($tenantTables as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        Schema::dropIfExists('stores');
        Schema::dropIfExists('tenants');
    }
};
