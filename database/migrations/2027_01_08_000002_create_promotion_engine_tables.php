<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotion_campaigns')) {
            Schema::create('promotion_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('campaign_code', 64);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('type', 32); // AUTOMATIC_DISCOUNT, BUY_X_GET_Y, BUNDLE_PACKAGE, HAPPY_HOUR
                $table->dateTime('start_datetime');
                $table->dateTime('end_datetime');
                $table->json('allowed_days')->nullable();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->json('allowed_channels')->nullable();
                $table->json('allowed_customer_group_ids')->nullable();
                $table->integer('priority')->default(10);
                $table->unsignedInteger('max_usage_limit')->nullable();
                $table->unsignedInteger('max_usage_per_customer')->nullable()->default(1);
                $table->unsignedInteger('current_usage_count')->default(0);
                $table->boolean('is_stackable')->default(false);
                $table->string('status', 20)->default('DRAFT'); // DRAFT, SCHEDULED, ACTIVE, PAUSED, EXPIRED, ARCHIVED
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'campaign_code'], 'uniq_promo_tenant_code');
                $table->index(['tenant_id', 'status', 'start_datetime', 'end_datetime'], 'idx_promo_tenant_status_dates');
                $table->index(['tenant_id', 'store_id'], 'idx_promo_tenant_store');
            });
        }

        if (! Schema::hasTable('promotion_rules')) {
            Schema::create('promotion_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('campaign_id');
                $table->string('rule_type', 50); // CART_TOTAL, PRODUCT_MATCH, CATEGORY_MATCH, BXGY_TRIGGER
                $table->json('trigger_product_ids')->nullable();
                $table->json('trigger_category_ids')->nullable();
                $table->decimal('min_order_amount', 15, 2)->default(0.00);
                $table->integer('min_order_qty')->default(1);
                $table->timestamps();

                $table->index(['tenant_id', 'campaign_id'], 'idx_promo_rules_tenant_campaign');
                $table->foreign('campaign_id')
                    ->references('id')
                    ->on('promotion_campaigns')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('promotion_rewards')) {
            Schema::create('promotion_rewards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('rule_id');
                $table->string('reward_type', 50); // PERCENTAGE_DISCOUNT, FIXED_AMOUNT_DISCOUNT, FREE_PRODUCT, SPECIAL_PRICE_PRODUCT
                $table->decimal('discount_percentage', 5, 2)->nullable();
                $table->decimal('discount_fixed_amount', 15, 2)->nullable();
                $table->decimal('max_discount_amount', 15, 2)->nullable();
                $table->unsignedBigInteger('reward_product_id')->nullable();
                $table->decimal('reward_product_discount_percent', 5, 2)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'rule_id'], 'idx_promo_rewards_tenant_rule');
                $table->foreign('rule_id')
                    ->references('id')
                    ->on('promotion_rules')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('promotion_store_mappings')) {
            Schema::create('promotion_store_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('store_id');
                $table->timestamps();

                $table->unique(['tenant_id', 'campaign_id', 'store_id'], 'uniq_promo_store_mapping');
                $table->foreign('campaign_id')
                    ->references('id')
                    ->on('promotion_campaigns')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_store_mappings');
        Schema::dropIfExists('promotion_rewards');
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotion_campaigns');
    }
};
