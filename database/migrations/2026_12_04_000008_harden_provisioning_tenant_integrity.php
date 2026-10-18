<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('owner_onboarding_tokens')
            ->select('tenant_id', 'user_id')
            ->groupBy('tenant_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate onboarding tokens exist for a tenant/user pair; resolve them before migration.');
        }

        if (DB::table('provisioning_requests as pr')
            ->join('users as u', 'u.id', '=', 'pr.result_user_id')
            ->whereNotNull('pr.result_user_id')
            ->whereColumn('u.tenant_id', '<>', 'pr.result_tenant_id')
            ->exists()) {
            throw new RuntimeException('Provisioning ledger result user/tenant mismatch; resolve data before migration.');
        }

        if (! Schema::hasIndex('owner_onboarding_tokens', 'onboarding_tokens_tenant_user_unique')) {
            Schema::table('owner_onboarding_tokens', function ($table) {
                $table->unique(['tenant_id', 'user_id'], 'onboarding_tokens_tenant_user_unique');
            });
        }

        if (Schema::hasIndex('provisioning_requests', 'provisioning_requests_result_user_fk')) {
            Schema::table('provisioning_requests', function ($table) {
                $table->dropForeign('provisioning_requests_result_user_fk');
            });
        }

        if (! Schema::hasIndex('provisioning_requests', 'provisioning_requests_result_tenant_user_fk')) {
            Schema::table('provisioning_requests', function ($table) {
                $table->foreign(['result_tenant_id', 'result_user_id'], 'provisioning_requests_result_tenant_user_fk')
                    ->references(['tenant_id', 'id'])->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('provisioning_requests', function ($table) {
            $table->dropForeign('provisioning_requests_result_tenant_user_fk');
            $table->foreign('result_user_id', 'provisioning_requests_result_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('owner_onboarding_tokens', function ($table) {
            $table->dropUnique('onboarding_tokens_tenant_user_unique');
        });
    }
};
