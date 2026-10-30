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
        if (! Schema::hasTable('shift_templates')) {
            Schema::create('shift_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('shift_code', 32);
                $table->string('name', 100);
                $table->time('start_time');
                $table->time('end_time');
                $table->boolean('cross_midnight')->default(false);
                $table->unsignedInteger('unpaid_break_minutes')->default(60);
                $table->unsignedInteger('grace_period_minutes')->default(15);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'store_id', 'shift_code'], 'uq_shift_templates_tenant_store_code');
                $table->index(['tenant_id', 'store_id', 'is_active'], 'idx_shift_templates_lookup');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
    }
};
