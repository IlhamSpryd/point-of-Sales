<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provisioning_requests') && ! Schema::hasColumn('provisioning_requests', 'purchased_billing_cycle')) {
            Schema::table('provisioning_requests', function (Blueprint $table): void {
                $table->string('purchased_billing_cycle', 20)->nullable()->after('plan_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('provisioning_requests') && Schema::hasColumn('provisioning_requests', 'purchased_billing_cycle')) {
            Schema::table('provisioning_requests', function (Blueprint $table): void {
                $table->dropColumn('purchased_billing_cycle');
            });
        }
    }
};
