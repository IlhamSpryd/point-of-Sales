<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Outbound Webhook Engine (Modul 18 INT / Fase 3).
     */
    public function up(): void
    {
        if (! Schema::hasTable('webhook_subscriptions')) {
            Schema::create('webhook_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('name', 128); // Nama integrasi (e.g. n8n Order Notification)
                $table->string('target_url', 512); // Endpoint penerima
                $table->string('secret_key', 128); // Kunci rahasia HMAC-SHA256
                $table->json('subscribed_events'); // Array event, e.g. ["order.completed", "inventory.low"]
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('failure_count')->default(0);
                $table->timestamp('last_delivery_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_webhook_subs_tenant_id');
                $table->index(['tenant_id', 'is_active'], 'idx_webhook_subs_tenant_active');

                $table->foreign('tenant_id')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('webhook_delivery_logs')) {
            Schema::create('webhook_delivery_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('subscription_id');
                $table->string('delivery_id', 64);
                $table->string('event', 64);
                $table->longText('payload');
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_body')->nullable();
                $table->unsignedTinyInteger('attempt')->default(1);
                $table->string('status', 32)->default('pending'); // pending, delivered, failed, dead_letter
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_webhook_logs_tenant_id');
                $table->index(['tenant_id', 'status', 'created_at'], 'idx_webhook_logs_tenant_status');
                $table->index(['tenant_id', 'delivery_id'], 'idx_webhook_logs_delivery_id');

                $table->foreign(['tenant_id', 'subscription_id'])
                    ->references(['tenant_id', 'id'])->on('webhook_subscriptions')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_logs');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
