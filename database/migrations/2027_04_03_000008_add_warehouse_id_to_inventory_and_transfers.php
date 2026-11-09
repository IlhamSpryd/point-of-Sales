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
     * Implements TASK-302: DEC-001 Tier 4 Central Warehouse decoupling.
     */
    public function up(): void
    {
        // 1. stock_movements
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_movements', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('store_id');
                    $table->index(['tenant_id', 'warehouse_id'], 'idx_stock_movements_tenant_warehouse');
                }
            });

            if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('warehouses')) {
                try {
                    Schema::table('stock_movements', function (Blueprint $table) {
                        $table->foreign(['tenant_id', 'warehouse_id'], 'fk_stock_movements_warehouse_id')
                            ->references(['tenant_id', 'id'])->on('warehouses')
                            ->onDelete('restrict')->onUpdate('cascade');
                    });
                } catch (Throwable) {
                }
            }
        }

        // 2. product_stock_balances
        if (Schema::hasTable('product_stock_balances')) {
            Schema::table('product_stock_balances', function (Blueprint $table) {
                if (! Schema::hasColumn('product_stock_balances', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('store_id');
                    $table->index(['tenant_id', 'warehouse_id'], 'idx_stock_balances_tenant_warehouse');
                }
            });

            if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('warehouses')) {
                try {
                    Schema::table('product_stock_balances', function (Blueprint $table) {
                        $table->foreign(['tenant_id', 'warehouse_id'], 'fk_product_stock_balances_warehouse_id')
                            ->references(['tenant_id', 'id'])->on('warehouses')
                            ->onDelete('restrict')->onUpdate('cascade');
                    });
                } catch (Throwable) {
                }
            }
        }

        // 3. stock_transfers
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_transfers', 'from_warehouse_id')) {
                    $table->unsignedBigInteger('from_warehouse_id')->nullable()->after('to_store_id');
                    $table->unsignedBigInteger('to_warehouse_id')->nullable()->after('from_warehouse_id');
                    $table->index(['tenant_id', 'from_warehouse_id'], 'idx_transfers_from_warehouse');
                    $table->index(['tenant_id', 'to_warehouse_id'], 'idx_transfers_to_warehouse');
                }
            });

            if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('warehouses')) {
                try {
                    Schema::table('stock_transfers', function (Blueprint $table) {
                        $table->foreign(['tenant_id', 'from_warehouse_id'], 'fk_transfers_from_warehouse_id')
                            ->references(['tenant_id', 'id'])->on('warehouses')
                            ->onDelete('restrict')->onUpdate('cascade');
                        $table->foreign(['tenant_id', 'to_warehouse_id'], 'fk_transfers_to_warehouse_id')
                            ->references(['tenant_id', 'id'])->on('warehouses')
                            ->onDelete('restrict')->onUpdate('cascade');
                    });
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_transfers') && Schema::hasColumn('stock_transfers', 'from_warehouse_id')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    try {
                        $table->dropForeign('fk_transfers_from_warehouse_id');
                        $table->dropForeign('fk_transfers_to_warehouse_id');
                    } catch (Throwable) {
                    }
                }
                $table->dropIndex('idx_transfers_from_warehouse');
                $table->dropIndex('idx_transfers_to_warehouse');
                $table->dropColumn(['from_warehouse_id', 'to_warehouse_id']);
            });
        }

        if (Schema::hasTable('product_stock_balances') && Schema::hasColumn('product_stock_balances', 'warehouse_id')) {
            Schema::table('product_stock_balances', function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    try {
                        $table->dropForeign('fk_product_stock_balances_warehouse_id');
                    } catch (Throwable) {
                    }
                }
                $table->dropIndex('idx_stock_balances_tenant_warehouse');
                $table->dropColumn('warehouse_id');
            });
        }

        if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'warehouse_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    try {
                        $table->dropForeign('fk_stock_movements_warehouse_id');
                    } catch (Throwable) {
                    }
                }
                $table->dropIndex('idx_stock_movements_tenant_warehouse');
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
