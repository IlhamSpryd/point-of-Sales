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
        // 1. Tabel Customer Credit Accounts
        Schema::create('customer_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');

            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->unsignedInteger('grace_period_days')->default(0);
            $table->string('credit_status', 20)->default('ACTIVE'); // ACTIVE, ON_HOLD, SUSPENDED
            $table->string('risk_rating', 20)->default('NORMAL');   // LOW, NORMAL, HIGH, CRITICAL

            $table->decimal('reserved_exposure', 18, 2)->default(0);
            $table->decimal('invoiced_exposure', 18, 2)->default(0);
            $table->decimal('unapplied_credit_balance', 18, 2)->default(0);

            $table->timestamp('last_reconciled_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            // Constraints & Indexes
            $table->unique(['tenant_id', 'customer_id'], 'cca_tenant_customer_unique');
            $table->index(['tenant_id', 'id'], 'cca_tenant_id_composite_index');
            $table->index(['tenant_id', 'credit_status'], 'cca_tenant_status_index');

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('cascade');

            $table->foreign('updated_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });

        // 2. Tabel Customer Credit Logs (Immutable audit log perubahan kredit)
        Schema::create('customer_credit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');

            $table->string('event_type', 50); // LIMIT_CHANGED, TERMS_CHANGED, STATUS_HOLD, STATUS_RELEASE, CREDIT_OVERRIDE, EXPOSURE_RESERVED, EXPOSURE_RELEASED
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Constraints & Indexes
            $table->index(['tenant_id', 'customer_id'], 'ccl_tenant_customer_index');
            $table->index(['tenant_id', 'id'], 'ccl_tenant_id_composite_index');
            $table->index(['tenant_id', 'event_type'], 'ccl_tenant_event_index');

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('cascade');

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_credit_logs');
        Schema::dropIfExists('customer_credit_accounts');
    }
};
