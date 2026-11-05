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

        // 1. Add columns to stock_movements if they don't exist
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'variant_id')) {
                $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('stock_movements', 'balance_before')) {
                $table->decimal('balance_before', 18, 4)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('stock_movements', 'balance_after')) {
                $table->decimal('balance_after', 18, 4)->default(0)->after('balance_before');
            }
            if (! Schema::hasColumn('stock_movements', 'reference_type')) {
                $table->string('reference_type', 50)->nullable()->after('reason');
            }
            if (! Schema::hasColumn('stock_movements', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            }
        });

        // 2. Add reserved_quantity to product_stock_balances & ingredient_stock_balances
        Schema::table('product_stock_balances', function (Blueprint $table) {
            if (! Schema::hasColumn('product_stock_balances', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 18, 4)->default(0)->after('quantity');
            }
        });

        Schema::table('ingredient_stock_balances', function (Blueprint $table) {
            if (! Schema::hasColumn('ingredient_stock_balances', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 18, 4)->default(0)->after('quantity');
            }
        });

        // 3. Create kitchen_waste_logs
        if (! Schema::hasTable('kitchen_waste_logs')) {
            Schema::create('kitchen_waste_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('ingredient_id');
                $table->decimal('quantity', 18, 4);
                $table->decimal('cost_price', 18, 4)->default(0);
                $table->decimal('total_loss_amount', 18, 4)->default(0);
                $table->string('reason', 255)->default('SPOILAGE_WASTE');
                $table->dateTime('waste_date');
                $table->unsignedBigInteger('logged_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'store_id', 'waste_date'], 'kwl_tenant_store_date_idx');
                $table->foreign(['tenant_id', 'ingredient_id'], 'kwl_ingredient_fk')
                    ->references(['tenant_id', 'id'])->on('ingredients')
                    ->onDelete('restrict');
            });
        }

        // 4. Update check constraints on stock_movements.type and ingredient_stock_movements.type
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropConstraintIfExists('stock_movements', 'chk_sm_type_enum');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_sm_type_enum CHECK (LOWER(type) IN (
                'restore_compensation','sale_deduction','initial_sync','adjustment',
                'goods_receipt','goods_receipt_reversal','transfer_out','transfer_in',
                'transfer_return_in','transfer_shrinkage','adjustment_in','adjustment_out',
                'opname_adjustment','supplier_return_out','sale','purchase_receipt','return',
                'void_restore','sale_consumption','spoilage_waste','waste'
            ))");

            $this->dropConstraintIfExists('ingredient_stock_movements', 'chk_ism_type_enum');
            DB::statement("ALTER TABLE ingredient_stock_movements ADD CONSTRAINT chk_ism_type_enum CHECK (LOWER(type) IN (
                'sale_deduction','purchase_receipt','waste','adjustment','restore_compensation','initial_sync',
                'goods_receipt','goods_receipt_reversal','transfer_out','transfer_in',
                'transfer_return_in','transfer_shrinkage','adjustment_in','adjustment_out',
                'opname_adjustment','supplier_return_out','sale','return','void_restore',
                'sale_consumption','spoilage_waste'
            ))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_waste_logs');
    }

    private function dropConstraintIfExists(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        } catch (Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            } catch (Throwable) {
                // Ignore if not exists
            }
        }
    }
};
