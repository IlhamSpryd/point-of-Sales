<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('employee_id')->unique()->nullable()->after('id'));
        Schema::table('roles', fn (Blueprint $table) => $table->string('role_code')->unique()->nullable()->after('id'));
        Schema::table('categories', fn (Blueprint $table) => $table->string('category_code')->unique()->nullable()->after('id'));
        Schema::table('products', fn (Blueprint $table) => $table->string('product_code')->unique()->nullable()->after('id'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('employee_id'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('role_code'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('category_code'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('product_code'));
    }
};
