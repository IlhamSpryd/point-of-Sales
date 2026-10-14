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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pin_hash')) {
                if (Schema::hasColumn('users', 'pin_code')) {
                    $table->string('pin_hash', 255)->nullable()->after('pin_code');
                } else {
                    $table->string('pin_hash', 255)->nullable();
                }
            }
            if (! Schema::hasColumn('users', 'password_hash')) {
                $table->string('password_hash', 255)->nullable()->after('password');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'password_hash']);
        });
    }
};
