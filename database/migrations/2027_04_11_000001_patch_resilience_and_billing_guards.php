<?php

declare(strict_types=1);

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
        // 1. Tambah expected_amount pada provisioning_requests untuk anti-tampering webhook
        if (Schema::hasTable('provisioning_requests') && ! Schema::hasColumn('provisioning_requests', 'expected_amount')) {
            Schema::table('provisioning_requests', function (Blueprint $table) {
                $table->decimal('expected_amount', 15, 2)->nullable()->after('plan_id');
            });
        }

        // 2. Tambah offline_post_close_reconciliation pada orders untuk shared-tablet reconciliation
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'offline_post_close_reconciliation')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('offline_post_close_reconciliation')->default(false)->after('is_offline_sync');
                $table->index(['tenant_id', 'offline_post_close_reconciliation'], 'idx_orders_offline_recon');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'offline_post_close_reconciliation')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('idx_orders_offline_recon');
                $table->dropColumn('offline_post_close_reconciliation');
            });
        }

        if (Schema::hasTable('provisioning_requests') && Schema::hasColumn('provisioning_requests', 'expected_amount')) {
            Schema::table('provisioning_requests', function (Blueprint $table) {
                $table->dropColumn('expected_amount');
            });
        }
    }
};
