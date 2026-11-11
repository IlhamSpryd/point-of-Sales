<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'dashboard_archetype')) {
                $table->string('dashboard_archetype', 64)->default('executive')->after('permissions');
                $table->index(['tenant_id', 'dashboard_archetype'], 'idx_roles_tenant_archetype');
            }

            if (! Schema::hasColumn('roles', 'is_system_default')) {
                $table->boolean('is_system_default')->default(false)->after('dashboard_archetype');
                $table->index(['tenant_id', 'is_system_default'], 'idx_roles_tenant_system_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'dashboard_archetype')) {
                $table->dropIndex('idx_roles_tenant_archetype');
                $table->dropColumn('dashboard_archetype');
            }

            if (Schema::hasColumn('roles', 'is_system_default')) {
                $table->dropIndex('idx_roles_tenant_system_default');
                $table->dropColumn('is_system_default');
            }
        });
    }
};
