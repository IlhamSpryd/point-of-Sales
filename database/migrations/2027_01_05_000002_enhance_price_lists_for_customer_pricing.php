<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Modul 6 Fase 2: Perluasan price_lists dan price_list_items untuk
     * Customer-Specific Pricing, Customer Group Pricing, dan Quantity Breaks.
     */
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            if (! Schema::hasColumn('price_lists', 'target_type')) {
                $table->string('target_type', 30)->default('GENERAL')->after('type');
            }

            if (! Schema::hasColumn('price_lists', 'customer_group_id')) {
                $table->unsignedBigInteger('customer_group_id')->nullable()->after('customer_id');
            }

            if (! Schema::hasColumn('price_lists', 'approved_by_user_id')) {
                $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('priority');
            }

            if (! Schema::hasColumn('price_lists', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            }

            if (! Schema::hasColumn('price_lists', 'allow_promo_stacking')) {
                $table->boolean('allow_promo_stacking')->default(false)->after('is_active');
            }

            $table->index(['tenant_id', 'target_type', 'customer_id', 'is_active'], 'pl_tenant_target_customer_idx');
            $table->index(['tenant_id', 'target_type', 'customer_group_id', 'is_active'], 'pl_tenant_target_group_idx');

            $table->foreign('customer_group_id', 'pl_customer_group_fk')
                ->references('id')->on('customer_groups')
                ->onDelete('set null');

            $table->foreign('approved_by_user_id', 'pl_approved_by_user_fk')
                ->references('id')->on('users')
                ->onDelete('set null');
        });

        Schema::table('price_list_items', function (Blueprint $table) {
            if (! Schema::hasColumn('price_list_items', 'max_quantity')) {
                $table->decimal('max_quantity', 15, 4)->nullable()->after('min_quantity');
            }

            $table->index(
                ['tenant_id', 'price_list_id', 'product_id', 'product_variant_id', 'min_quantity'],
                'pli_tenant_lookup_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropIndex('pli_tenant_lookup_idx');
            if (Schema::hasColumn('price_list_items', 'max_quantity')) {
                $table->dropColumn('max_quantity');
            }
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropForeign('pl_customer_group_fk');
            $table->dropForeign('pl_approved_by_user_fk');

            $table->dropIndex('pl_tenant_target_customer_idx');
            $table->dropIndex('pl_tenant_target_group_idx');

            $columnsToDrop = [];
            foreach (['target_type', 'customer_group_id', 'approved_by_user_id', 'approved_at', 'allow_promo_stacking'] as $col) {
                if (Schema::hasColumn('price_lists', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
