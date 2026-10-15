<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 — FK constraints untuk memastikan hasil ledger/token/outbox tidak
 * menunjuk ke tenant/user yang tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_requests') || ! Schema::hasTable('owner_onboarding_tokens') || ! Schema::hasTable('email_outbox')) {
            throw new RuntimeException('Provisioning tables must exist before foreign keys are added.');
        }

        $existingFks = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = "FOREIGN KEY"'
        );
        $fkNames = array_column($existingFks, 'CONSTRAINT_NAME');

        if (! in_array('provisioning_requests_result_tenant_fk', $fkNames, true)) {
            Schema::table('provisioning_requests', function (Blueprint $table) {
                $table->foreign('result_tenant_id', 'provisioning_requests_result_tenant_fk')
                    ->references('id')->on('tenants')->nullOnDelete();
                $table->foreign('result_user_id', 'provisioning_requests_result_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! in_array('onboarding_tokens_tenant_user_fk', $fkNames, true)) {
            Schema::table('owner_onboarding_tokens', function (Blueprint $table) {
                $table->foreign(['tenant_id', 'user_id'], 'onboarding_tokens_tenant_user_fk')
                    ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            });
        }

        if (! in_array('email_outbox_tenant_fk', $fkNames, true)) {
            Schema::table('email_outbox', function (Blueprint $table) {
                $table->foreign('tenant_id', 'email_outbox_tenant_fk')
                    ->references('id')->on('tenants')->nullOnDelete();
                $table->foreign('user_id', 'email_outbox_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table) {
            $table->dropForeign('email_outbox_tenant_fk');
            $table->dropForeign('email_outbox_user_fk');
        });

        Schema::table('owner_onboarding_tokens', function (Blueprint $table) {
            $table->dropForeign('onboarding_tokens_tenant_user_fk');
        });

        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropForeign('provisioning_requests_result_tenant_fk');
            $table->dropForeign('provisioning_requests_result_user_fk');
        });
    }
};
