<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofd_channel_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->string('provider', 32); // grabfood, gofood, shopeefood
            $table->string('merchant_id', 100);
            $table->string('client_id', 100)->nullable();
            $table->text('client_secret')->nullable();
            $table->text('signing_secret')->nullable();
            $table->decimal('commission_rate_pct', 5, 2)->default(20.00);
            $table->decimal('price_markup_pct', 5, 2)->default(0.00);
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->boolean('auto_accept_order')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'store_id', 'provider'], 'uniq_ofd_tenant_store_provider');
            $table->index(['tenant_id', 'provider', 'merchant_id'], 'idx_ofd_tenant_provider_merchant');
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'order_channel')) {
                $table->string('order_channel', 32)->nullable()->default('pos_cashier')->after('origin_channel');
            }
            if (! Schema::hasColumn('orders', 'external_order_id')) {
                $table->string('external_order_id', 100)->nullable()->after('order_channel');
            }
            if (! Schema::hasColumn('orders', 'external_short_code')) {
                $table->string('external_short_code', 32)->nullable()->after('external_order_id');
            }
            if (! Schema::hasColumn('orders', 'driver_name')) {
                $table->string('driver_name', 100)->nullable()->after('external_short_code');
            }
            if (! Schema::hasColumn('orders', 'driver_phone')) {
                $table->string('driver_phone', 50)->nullable()->after('driver_name');
            }
            if (! Schema::hasColumn('orders', 'platform_commission_rate')) {
                $table->decimal('platform_commission_rate', 5, 2)->nullable()->default(0.00)->after('driver_phone');
            }
            if (! Schema::hasColumn('orders', 'platform_commission_amount')) {
                $table->decimal('platform_commission_amount', 15, 2)->default(0.00)->after('platform_commission_rate');
            }
            if (! Schema::hasColumn('orders', 'merchant_promo_subsidy')) {
                $table->decimal('merchant_promo_subsidy', 15, 2)->default(0.00)->after('platform_commission_amount');
            }
            if (! Schema::hasColumn('orders', 'net_payout_amount')) {
                $table->decimal('net_payout_amount', 15, 2)->default(0.00)->after('merchant_promo_subsidy');
            }

            $table->index(['tenant_id', 'order_channel', 'external_order_id'], 'idx_orders_tenant_channel_ext_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_tenant_channel_ext_id');
            $table->dropColumn([
                'order_channel',
                'external_order_id',
                'external_short_code',
                'driver_name',
                'driver_phone',
                'platform_commission_rate',
                'platform_commission_amount',
                'merchant_promo_subsidy',
                'net_payout_amount',
            ]);
        });

        Schema::dropIfExists('ofd_channel_configs');
    }
};
