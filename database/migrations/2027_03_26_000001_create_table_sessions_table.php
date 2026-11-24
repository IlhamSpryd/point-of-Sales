<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('table_id');
            $table->string('session_token', 64);
            $table->string('check_in_pin', 4)->nullable();
            $table->string('status', 20)->default('active'); // active, billed, closed, expired
            $table->string('guest_name', 100)->nullable();
            $table->unsignedInteger('guest_count')->default(1);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'session_token'], 'idx_table_sessions_tenant_token');
            $table->index(['tenant_id', 'store_id', 'table_id', 'status'], 'idx_table_sessions_lookup');
            $table->index(['tenant_id', 'table_id', 'status', 'expires_at'], 'idx_table_sessions_active');
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'table_session_id')) {
                $table->unsignedBigInteger('table_session_id')->nullable()->after('table_id');
                $table->index(['tenant_id', 'table_session_id'], 'idx_orders_tenant_table_session');
            }
            if (! Schema::hasColumn('orders', 'payment_timing')) {
                $table->string('payment_timing', 20)->default('pay_first')->after('payment_method');
            }
            if (! Schema::hasColumn('orders', 'sent_to_kitchen_at')) {
                $table->timestamp('sent_to_kitchen_at')->nullable()->after('payment_timing');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'sent_to_kitchen_at')) {
                $table->dropColumn('sent_to_kitchen_at');
            }
            if (Schema::hasColumn('orders', 'payment_timing')) {
                $table->dropColumn('payment_timing');
            }
            if (Schema::hasColumn('orders', 'table_session_id')) {
                $table->dropIndex('idx_orders_tenant_table_session');
                $table->dropColumn('table_session_id');
            }
        });

        Schema::dropIfExists('table_sessions');
    }
};
