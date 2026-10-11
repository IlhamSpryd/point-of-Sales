<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provisioning_requests') || DB::table('migrations')->where('migration', 'z_01_create_provisioning_requests_table')->exists()) {
            return;
        }

        Schema::create('provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->string('owner_email', 255);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('result_tenant_id')->nullable();
            $table->unsignedBigInteger('result_user_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();
            $table->index(['owner_email', 'status']);
            $table->index(['status', 'started_at']);
            $table->index('result_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_requests');
    }
};
