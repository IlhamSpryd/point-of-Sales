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
            $table->string('phone_number')->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->softDeletes();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('description');
            $table->softDeletes();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->unique()->nullable()->after('name');
            $table->string('barcode')->unique()->nullable()->after('sku');
            $table->text('description')->nullable()->after('barcode');
            $table->bigInteger('cost_price')->default(0)->after('description');
            $table->boolean('is_active')->default(true)->after('stock');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'is_active']);
            $table->dropSoftDeletes();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_active']);
            $table->dropSoftDeletes();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sku', 'barcode', 'description', 'cost_price', 'is_active']);
            $table->dropSoftDeletes();
        });
    }
};
