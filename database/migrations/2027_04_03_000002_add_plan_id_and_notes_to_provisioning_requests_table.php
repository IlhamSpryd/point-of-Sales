<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * TASK-004: Add plan_id and notes to provisioning_requests to persist plan upgrade requests.
     */
    public function up(): void
    {
        if (Schema::hasTable('provisioning_requests')) {
            Schema::table('provisioning_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('provisioning_requests', 'plan_id')) {
                    $table->unsignedBigInteger('plan_id')->nullable()->after('result_user_id');
                    $table->foreign('plan_id')
                        ->references('id')->on('plans')
                        ->onDelete('set null');
                }
                if (! Schema::hasColumn('provisioning_requests', 'notes')) {
                    $table->text('notes')->nullable()->after('plan_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('provisioning_requests')) {
            Schema::table('provisioning_requests', function (Blueprint $table) {
                if (Schema::hasColumn('provisioning_requests', 'plan_id')) {
                    $table->dropForeign(['plan_id']);
                    $table->dropColumn('plan_id');
                }
                if (Schema::hasColumn('provisioning_requests', 'notes')) {
                    $table->dropColumn('notes');
                }
            });
        }
    }
};
