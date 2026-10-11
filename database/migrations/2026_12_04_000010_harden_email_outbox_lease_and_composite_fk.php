<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 (TX-002, TX-003) — Pisahkan lease worker dari jadwal retry outbox,
 * tambahkan index lease expiration, dan tegakkan composite FK tenant consistency.
 *
 * Forward-only & idempotent schema updates:
 *  - email_outbox.lease_expires_at: memisahkan worker claim lease dari next_attempt_at (retry schedule).
 *  - index (status, lease_expires_at) pada email_outbox dan provisioning_requests.
 *  - composite FK (tenant_id, user_id) pada email_outbox -> users(tenant_id, id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table) {
            if (! Schema::hasColumn('email_outbox', 'lease_expires_at')) {
                $table->timestamp('lease_expires_at')->nullable()->after('next_attempt_at');
            }
        });

        Schema::table('email_outbox', function (Blueprint $table) {
            // Index status + lease_expires_at untuk recovery dispatcher
            $table->index(['status', 'lease_expires_at'], 'email_outbox_status_lease_expires_at_index');

            // Composite FK tenant-user integrity
            $table->foreign(['tenant_id', 'user_id'], 'email_outbox_tenant_user_composite_fk')
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('provisioning_requests', function (Blueprint $table) {
            // Index status + lease_expires_at untuk claim expiration & reconciliation
            $table->index(['status', 'lease_expires_at'], 'provisioning_requests_status_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropIndex('provisioning_requests_status_lease_index');
        });

        Schema::table('email_outbox', function (Blueprint $table) {
            $table->dropForeign('email_outbox_tenant_user_composite_fk');
            $table->dropIndex('email_outbox_status_lease_expires_at_index');
            $table->dropColumn('lease_expires_at');
        });
    }
};
