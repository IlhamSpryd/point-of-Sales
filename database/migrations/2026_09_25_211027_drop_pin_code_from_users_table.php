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
        if (Schema::hasColumn('users', 'pin_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pin_code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'pin_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pin_code', 255)->nullable()->after('password_hash');
            });
        }
    }
};
