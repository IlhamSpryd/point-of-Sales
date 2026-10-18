<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambahkan kolom amount_tendered dan change_amount pada tabel payments
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'amount_tendered')) {
                $table->unsignedBigInteger('amount_tendered')->nullable()->after('amount');
            }
            if (! Schema::hasColumn('payments', 'change_amount')) {
                $table->unsignedBigInteger('change_amount')->default(0)->after('amount_tendered');
            }
        });

        // 2. Buat tabel payment_attempts
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('idempotency_key', 100);
            $table->string('provider', 32); // cash | midtrans | manual_edc
            $table->string('payment_method', 32);
            $table->string('provider_order_id', 128)->nullable();
            $table->string('provider_transaction_id', 128)->nullable();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 32)->default('initiated');
            $table->json('raw_request_meta')->nullable();
            $table->json('raw_response_meta')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            // Unique indexes
            $table->unique(['tenant_id', 'id'], 'uq_payment_attempts_tenant_id');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_payment_attempts_idempotency');
            $table->unique(['tenant_id', 'provider', 'provider_order_id'], 'uq_payment_attempts_provider_order');

            // Indexes
            $table->index(['tenant_id', 'store_id', 'order_id'], 'idx_pa_tenant_store_order');
            $table->index(['tenant_id', 'status'], 'idx_pa_tenant_status');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'fk_pa_tenant_store')
                ->references(['tenant_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'order_id'], 'fk_pa_tenant_order')
                ->references(['tenant_id', 'id'])
                ->on('orders')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'fk_pa_tenant_store_device')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('devices')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'fk_pa_tenant_store_shift')
                ->references(['tenant_id', 'store_id', 'id'])
                ->on('shifts')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'payment_id'], 'fk_pa_tenant_payment')
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->restrictOnDelete();

            $table->foreign('initiated_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // 3. Tambahkan check constraint untuk payment_attempts.status
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT chk_payment_attempts_status CHECK (`status` IN ('initiated', 'pending_provider', 'unknown_timeout', 'succeeded', 'failed', 'expired', 'cancelled'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'change_amount')) {
                $table->dropColumn('change_amount');
            }
            if (Schema::hasColumn('payments', 'amount_tendered')) {
                $table->dropColumn('amount_tendered');
            }
        });
    }
};
