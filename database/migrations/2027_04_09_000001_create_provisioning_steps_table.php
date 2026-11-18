<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * EXT-03 / R-19: Step-tracked idempotent tenant provisioning ledger.
     */
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_steps')) {
            Schema::create('provisioning_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('provisioning_request_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('step_key');
                $table->string('status', 32)->default('PENDING');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['provisioning_request_id', 'step_key'], 'uq_prov_steps_request_step');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provisioning_steps');
    }
};
