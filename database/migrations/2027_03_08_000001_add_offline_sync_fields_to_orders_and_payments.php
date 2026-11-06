<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TR-003 / DEC-006: Implementasi Mode Kasir Luring Penuh (Offline-Resilient POS Mode).
     * Menambahkan kolom pelacakan transaksi offline sync pada orders dan payments.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_offline_sync')->default(false)->after('order_status');
            $table->timestamp('offline_synced_at')->nullable()->after('is_offline_sync');
            $table->string('offline_client_uuid', 64)->nullable()->after('offline_synced_at');
            $table->index(['tenant_id', 'offline_client_uuid'], 'idx_orders_offline_uuid');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_offline_sync')->default(false)->after('status');
            $table->timestamp('offline_synced_at')->nullable()->after('is_offline_sync');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_offline_uuid');
            $table->dropColumn(['is_offline_sync', 'offline_synced_at', 'offline_client_uuid']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['is_offline_sync', 'offline_synced_at']);
        });
    }
};

