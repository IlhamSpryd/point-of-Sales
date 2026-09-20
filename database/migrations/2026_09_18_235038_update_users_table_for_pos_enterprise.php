<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Pastikan employee_id ada (jika langkah sebelumnya terlewat)
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id')->unique()->nullable()->after('id');
            }

            if (! Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number')->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'pin_code')) {
                $table->string('pin_code')->unique()->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'join_date')) {
                $table->date('join_date')->nullable()->after('pin_code');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role_id');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
            }

            // Tambahkan soft deletes jika belum ada
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'pin_code', 'join_date', 'is_active', 'last_login_at']);
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
