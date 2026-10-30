<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotion_reservations')) {
            Schema::create('promotion_reservations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('cart_uuid', 64);
                $table->decimal('reserved_amount', 15, 2)->default(0.00);
                $table->string('status', 20)->default('RESERVED'); // RESERVED, COMMITTED, RELEASED
                $table->dateTime('expires_at');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'campaign_id', 'status', 'expires_at'], 'idx_promo_res_campaign_status_exp');
                $table->index(['tenant_id', 'voucher_id', 'status', 'expires_at'], 'idx_promo_res_voucher_status_exp');
                $table->index(['cart_uuid'], 'idx_promo_res_cart_uuid');
                $table->index(['order_id'], 'idx_promo_res_order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_reservations');
    }
};
