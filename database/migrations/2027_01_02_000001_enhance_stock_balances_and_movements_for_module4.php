<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Extend product_stock_balances
        Schema::table('product_stock_balances', function (Blueprint $table) {
            $table->decimal('min_stock_level', 18, 4)->default(0)->after('baseline_movement_id');
            $table->decimal('reorder_point', 18, 4)->default(0)->after('min_stock_level');
            $table->decimal('max_stock_level', 18, 4)->nullable()->after('reorder_point');
            $table->decimal('average_unit_cost', 18, 4)->default(0)->after('max_stock_level');
            $table->decimal('last_unit_cost', 18, 4)->default(0)->after('average_unit_cost');
            $table->unsignedBigInteger('last_movement_id')->nullable()->after('last_unit_cost');
        });

        // 2. Extend ingredient_stock_balances
        Schema::table('ingredient_stock_balances', function (Blueprint $table) {
            $table->decimal('min_stock_level', 18, 4)->default(0)->after('baseline_movement_id');
            $table->decimal('reorder_point', 18, 4)->default(0)->after('min_stock_level');
            $table->decimal('max_stock_level', 18, 4)->nullable()->after('reorder_point');
            $table->decimal('average_unit_cost', 18, 4)->default(0)->after('max_stock_level');
            $table->decimal('last_unit_cost', 18, 4)->default(0)->after('average_unit_cost');
            $table->unsignedBigInteger('last_movement_id')->nullable()->after('last_unit_cost');
        });

        // 3. Extend stock_movements
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 4)->default(0)->after('quantity');
        });

        // 4. Extend ingredient_stock_movements (keep nullable to avoid violating append-only triggers)
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 4)->nullable()->default(0)->change();
        });

        // 5. Check constraints for MySQL/MariaDB
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE product_stock_balances ADD CONSTRAINT psb_min_stock_level_check CHECK (min_stock_level >= 0)');
            DB::statement('ALTER TABLE product_stock_balances ADD CONSTRAINT psb_reorder_point_check CHECK (reorder_point >= 0)');
            DB::statement('ALTER TABLE product_stock_balances ADD CONSTRAINT psb_max_stock_level_check CHECK (max_stock_level IS NULL OR max_stock_level >= reorder_point)');
            DB::statement('ALTER TABLE product_stock_balances ADD CONSTRAINT psb_avg_unit_cost_check CHECK (average_unit_cost >= 0)');
            DB::statement('ALTER TABLE product_stock_balances ADD CONSTRAINT psb_last_unit_cost_check CHECK (last_unit_cost >= 0)');

            DB::statement('ALTER TABLE ingredient_stock_balances ADD CONSTRAINT isb_min_stock_level_check CHECK (min_stock_level >= 0)');
            DB::statement('ALTER TABLE ingredient_stock_balances ADD CONSTRAINT isb_reorder_point_check CHECK (reorder_point >= 0)');
            DB::statement('ALTER TABLE ingredient_stock_balances ADD CONSTRAINT isb_max_stock_level_check CHECK (max_stock_level IS NULL OR max_stock_level >= reorder_point)');
            DB::statement('ALTER TABLE ingredient_stock_balances ADD CONSTRAINT isb_avg_unit_cost_check CHECK (average_unit_cost >= 0)');
            DB::statement('ALTER TABLE ingredient_stock_balances ADD CONSTRAINT isb_last_unit_cost_check CHECK (last_unit_cost >= 0)');

            // Movement type enum check constraints
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_sm_type_enum CHECK (type IN (
                'restore_compensation','sale_deduction','initial_sync','adjustment',
                'goods_receipt','goods_receipt_reversal','transfer_out','transfer_in',
                'transfer_return_in','transfer_shrinkage','adjustment_in','adjustment_out',
                'opname_adjustment','supplier_return_out'
            ))");

            $this->dropCheck('ingredient_stock_movements', 'chk_ism_type_enum');
            DB::statement("ALTER TABLE ingredient_stock_movements ADD CONSTRAINT chk_ism_type_enum CHECK (type IN (
                'sale_deduction','purchase_receipt','waste','adjustment','restore_compensation','initial_sync',
                'goods_receipt','goods_receipt_reversal','transfer_out','transfer_in',
                'transfer_return_in','transfer_shrinkage','adjustment_in','adjustment_out',
                'opname_adjustment','supplier_return_out'
            ))");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropCheck('product_stock_balances', 'psb_min_stock_level_check');
            $this->dropCheck('product_stock_balances', 'psb_reorder_point_check');
            $this->dropCheck('product_stock_balances', 'psb_max_stock_level_check');
            $this->dropCheck('product_stock_balances', 'psb_avg_unit_cost_check');
            $this->dropCheck('product_stock_balances', 'psb_last_unit_cost_check');

            $this->dropCheck('ingredient_stock_balances', 'isb_min_stock_level_check');
            $this->dropCheck('ingredient_stock_balances', 'isb_reorder_point_check');
            $this->dropCheck('ingredient_stock_balances', 'isb_max_stock_level_check');
            $this->dropCheck('ingredient_stock_balances', 'isb_avg_unit_cost_check');
            $this->dropCheck('ingredient_stock_balances', 'isb_last_unit_cost_check');

            $this->dropCheck('stock_movements', 'chk_sm_type_enum');

            $this->dropCheck('ingredient_stock_movements', 'chk_ism_type_enum');
            DB::statement("ALTER TABLE ingredient_stock_movements ADD CONSTRAINT chk_ism_type_enum CHECK (type IN ('sale_deduction','purchase_receipt','waste','adjustment','restore_compensation','initial_sync'))");
        }

        Schema::table('product_stock_balances', function (Blueprint $table) {
            $table->dropColumn([
                'min_stock_level',
                'reorder_point',
                'max_stock_level',
                'average_unit_cost',
                'last_unit_cost',
                'last_movement_id',
            ]);
        });

        Schema::table('ingredient_stock_balances', function (Blueprint $table) {
            $table->dropColumn([
                'min_stock_level',
                'reorder_point',
                'max_stock_level',
                'average_unit_cost',
                'last_unit_cost',
                'last_movement_id',
            ]);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost']);
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable()->change();
        });
    }

    private function dropCheck(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        } catch (Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            } catch (Throwable) {
                // Ignore if constraint does not exist
            }
        }
    }
};
