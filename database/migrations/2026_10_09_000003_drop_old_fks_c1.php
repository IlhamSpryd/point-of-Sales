<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_role_id_foreign');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->dropForeign('user_stores_store_id_foreign');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->dropForeign('user_stores_user_id_foreign');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id', 'users_role_id_foreign')->references('id')->on('roles')->onDelete('SET NULL');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->foreign('store_id', 'user_stores_store_id_foreign')->references('id')->on('stores')->onDelete('CASCADE');
        });
        Schema::table('user_stores', function (Blueprint $table) {
            $table->foreign('user_id', 'user_stores_user_id_foreign')->references('id')->on('users')->onDelete('CASCADE');
        });
    }
};
