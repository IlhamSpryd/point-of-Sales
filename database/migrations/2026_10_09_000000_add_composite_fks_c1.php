<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE stores MODIFY tenant_id bigint(20) unsigned DEFAULT NULL');
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'roles_tenant_id_id_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'users_tenant_id_id_unique');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'stores_tenant_id_id_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'role_id'], 'users_role_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('roles')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'store_id'], 'user_stores_store_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'user_id'], 'user_stores_user_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->onDelete('CASCADE')->onUpdate('CASCADE');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_role_id_composite_foreign');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->dropForeign('user_stores_store_id_composite_foreign');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->dropForeign('user_stores_user_id_composite_foreign');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_tenant_id_id_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_id_id_unique');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique('stores_tenant_id_id_unique');
        });
    }
};
