<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_targets')) {
            Schema::create('store_targets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('store_id');
                $table->string('target_period', 10); // Format: 'YYYY-MM' atau 'YYYY-MM-DD'
                $table->decimal('target_net_sales', 15, 2)->default(0);
                $table->decimal('target_gross_margin_pct', 5, 2)->default(0);
                $table->decimal('max_shrinkage_pct', 5, 2)->default(0);
                $table->decimal('max_labor_cost_pct', 5, 2)->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'store_id', 'target_period'], 'uniq_store_target_period');
                $table->index(['tenant_id', 'company_id', 'target_period'], 'idx_store_target_company_period');
                $table->index(['tenant_id', 'store_id'], 'idx_store_target_tenant_store');

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_targets');
    }
};
