<?php

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
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'user_agent')) {
                $table->string('user_agent', 255)->nullable()->after('ip_address');
            }
            if (! Schema::hasColumn('activity_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('user_agent');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('activity_logs', 'user_agent')) {
                $table->dropColumn('user_agent');
            }
            if (Schema::hasColumn('activity_logs', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
