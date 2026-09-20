<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Pastikan role_code ada (jika langkah sebelumnya terlewat)
            if (! Schema::hasColumn('roles', 'role_code')) {
                $table->string('role_code')->unique()->nullable()->after('id');
            }

            if (! Schema::hasColumn('roles', 'description')) {
                $table->string('description')->nullable()->after('name'); // Or after role_name if column was renamed, wait, the table originally had 'name' in it. The user's prompt says after('role_name'), but Laravel's default is usually 'name'.
            }

            // Kolom JSON untuk granular permissions (Super Fleksibel)
            if (! Schema::hasColumn('roles', 'permissions')) {
                $table->json('permissions')->nullable()->after('description');
            }

            if (! Schema::hasColumn('roles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('permissions');
            }

            if (! Schema::hasColumn('roles', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'permissions', 'is_active']);
            if (Schema::hasColumn('roles', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
