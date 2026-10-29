<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 1. fixed_asset_categories
        Schema::create('fixed_asset_categories', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('category_code', 50);
            $table->string('category_name', 150);
            $table->unsignedBigInteger('asset_gl_account_id');
            $table->unsignedBigInteger('accum_depr_gl_account_id');
            $table->unsignedBigInteger('depr_expense_gl_account_id');
            $table->unsignedInteger('useful_life_months')->default(48); // default 4 tahun
            $table->string('depreciation_method', 30)->default('STRAIGHT_LINE');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_fac_tenant_id');
            $table->unique(['tenant_id', 'category_code'], 'uq_fac_code');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_fac_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'asset_gl_account_id'], 'fk_fac_asset_acc')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accum_depr_gl_account_id'], 'fk_fac_accum_acc')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'depr_expense_gl_account_id'], 'fk_fac_depr_exp_acc')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');
            }
        });

        // 2. fixed_assets
        Schema::create('fixed_assets', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('category_id');
            $table->string('asset_code', 50);
            $table->string('asset_name', 150);
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0.00);
            $table->unsignedInteger('useful_life_months');
            $table->string('depreciation_method', 30)->default('STRAIGHT_LINE');
            $table->string('status', 30)->default('ACTIVE'); // DRAFT, ACTIVE, FULLY_DEPRECIATED, DISPOSED, IMPAIRED
            $table->decimal('accumulated_depreciation', 18, 2)->default(0.00);
            $table->decimal('net_book_value', 18, 2);
            $table->unsignedBigInteger('acquisition_journal_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('location_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_fa_tenant_id');
            $table->unique(['tenant_id', 'asset_code'], 'uq_fa_code');
            $table->index(['tenant_id', 'store_id', 'status'], 'idx_fa_status_store');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_fa_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_fa_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'category_id'], 'fk_fa_category')
                    ->references(['tenant_id', 'id'])
                    ->on('fixed_asset_categories')
                    ->onDelete('restrict');
            }
        });

        // 3. fixed_asset_depreciation_schedules
        Schema::create('fixed_asset_depreciation_schedules', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('fixed_asset_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('depreciation_amount', 18, 2);
            $table->decimal('accumulated_depreciation_after', 18, 2);
            $table->decimal('net_book_value_after', 18, 2);
            $table->string('status', 30)->default('PENDING'); // PENDING, POSTED
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_fads_tenant_id');
            $table->unique(['tenant_id', 'fixed_asset_id', 'accounting_period_id'], 'uq_fads_period');
            $table->index(['tenant_id', 'accounting_period_id', 'status'], 'idx_fads_pending');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_fads_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'fixed_asset_id'], 'fk_fads_asset')
                    ->references(['tenant_id', 'id'])
                    ->on('fixed_assets')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accounting_period_id'], 'fk_fads_period')
                    ->references(['tenant_id', 'id'])
                    ->on('accounting_periods')
                    ->onDelete('restrict');
            }
        });

        // 4. fixed_asset_disposals
        Schema::create('fixed_asset_disposals', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('fixed_asset_id');
            $table->date('disposal_date');
            $table->string('disposal_type', 30); // SALE, SCRAP, WRITE_OFF
            $table->decimal('proceeds_amount', 18, 2)->default(0.00);
            $table->decimal('book_value_at_disposal', 18, 2);
            $table->decimal('gain_loss_amount', 18, 2); // proceeds - book_value
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('cash_bank_account_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_fad_tenant_id');
            $table->index(['tenant_id', 'fixed_asset_id'], 'idx_fad_asset');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_fad_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_fad_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'fixed_asset_id'], 'fk_fad_asset')
                    ->references(['tenant_id', 'id'])
                    ->on('fixed_assets')
                    ->onDelete('restrict');
            }
        });

        // 5. inventory_valuation_snapshots
        Schema::create('inventory_valuation_snapshots', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->unsignedBigInteger('store_id');
            $table->date('snapshot_date');
            $table->string('item_type', 30); // PRODUCT, INGREDIENT
            $table->unsignedBigInteger('item_id');
            $table->decimal('closing_qty', 18, 4);
            $table->decimal('average_unit_cost', 18, 4);
            $table->decimal('total_valuation_amount', 18, 2);
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->decimal('gl_balance_amount', 18, 2)->default(0.00);
            $table->decimal('variance_amount', 18, 2)->default(0.00);
            $table->string('reconciliation_status', 30)->default('MATCHED'); // MATCHED, DISCREPANCY
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_ivs_tenant_id');
            $table->unique(['tenant_id', 'accounting_period_id', 'store_id', 'item_type', 'item_id'], 'uq_ivs_period_store_item');
            $table->index(['tenant_id', 'accounting_period_id', 'store_id'], 'idx_ivs_reconcile');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_ivs_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'accounting_period_id'], 'fk_ivs_period')
                    ->references(['tenant_id', 'id'])
                    ->on('accounting_periods')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'store_id'], 'fk_ivs_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuation_snapshots');
        Schema::dropIfExists('fixed_asset_disposals');
        Schema::dropIfExists('fixed_asset_depreciation_schedules');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
